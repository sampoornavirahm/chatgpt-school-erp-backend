<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\GoogleIdentityVerifier;
use App\Services\LicenseTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParentRelayAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school_erp.trial_enabled' => true]);
    }

    public function test_parent_reads_only_explicitly_linked_student_snapshots_and_feature_flags_default_off(): void
    {
        $school = $this->createSchool('North School', 'north-admin@example.test');
        $parent = $this->createParent($school, 'parent@example.test');
        $linked = (string) Str::uuid();
        $unlinked = (string) Str::uuid();
        $this->addSnapshot($school, $linked, 'Linked Child');
        $this->addSnapshot($school, $unlinked, 'Private Child');
        DB::table('relay_parent_links')->insert(['school_id' => $school->id, 'user_id' => $parent->id, 'student_public_id' => $linked, 'created_at' => now(), 'updated_at' => now()]);
        $token = $this->login($parent);

        $this->withToken($token)->getJson('/api/v1/parent/children')->assertOk()
            ->assertJsonCount(1, 'children')->assertJsonPath('children.0.snapshot.student.fullName', 'Linked Child')
            ->assertJsonPath('parentFeatures.courses', false)
            ->assertJsonPath('parentFeatures.transport', false)
            ->assertJsonPath('parentFeatures.ai', false);
        $this->withToken($token)->getJson("/api/v1/parent/children/{$unlinked}")->assertNotFound();
    }

    public function test_parent_can_manage_push_preferences_for_supported_categories(): void
    {
        $school = $this->createSchool('Notification School', 'notification-admin@example.test');
        $parent = $this->createParent($school, 'notification-parent@example.test');
        $token = $this->login($parent);

        $this->withToken($token)->getJson('/api/v1/parent/notification-preferences')->assertOk()
            ->assertJsonPath('preferences.school_notice', true)
            ->assertJsonPath('preferences.schedule_update', true)
            ->assertJsonPath('preferences.homework_update', true);
        $this->withToken($token)->postJson('/api/v1/parent/notification-preferences', [
            'category' => 'homework_update',
            'enabled' => false,
        ])->assertOk()->assertJsonPath('enabled', false);
        $this->withToken($token)->getJson('/api/v1/parent/notification-preferences')->assertOk()
            ->assertJsonPath('preferences.homework_update', false)
            ->assertJsonPath('preferences.school_notice', true);
        $this->withToken($token)->postJson('/api/v1/parent/notification-preferences', [
            'category' => 'unrecognised_category',
            'enabled' => false,
        ])->assertUnprocessable();

        $teacher = User::factory()->for($school)->state([
            'name' => 'Notification Teacher',
            'email' => 'notification-teacher@example.test',
            'password' => 'teacher-password-long',
            'role' => 'teacher',
            'is_active' => true,
        ])->create();
        $this->withToken($this->login($teacher))->getJson('/api/v1/parent/notification-preferences')->assertForbidden();
        $this->assertDatabaseHas('parent_notification_preferences', [
            'user_id' => $parent->id,
            'category' => 'homework_update',
            'enabled' => false,
        ]);
    }

    public function test_schedule_only_guardian_receives_only_child_identity_and_timetable(): void
    {
        $school = $this->createSchool('Schedule School', 'schedule-admin@example.test');
        $parent = $this->createParent($school, 'schedule-parent@example.test');
        $studentId = (string) Str::uuid();
        $this->addSnapshot($school, $studentId, 'Schedule Child');
        $payload = [
            'student' => ['fullName' => 'Schedule Child', 'admissionNo' => 'S-001', 'className' => 'Class 3', 'sectionName' => 'A'],
            'attendance' => [['date' => '2026-10-23', 'status' => 'absent']],
            'timetable' => [[
                'weekday' => 1,
                'period' => 1,
                'subjectName' => 'Mathematics',
                'teacherName' => 'Rina Patel',
            ]],
            'homework' => [['title' => 'Private homework']],
            'announcements' => [['title' => 'Private notice']],
            'results' => [['marksAwarded' => 12]],
            'fees' => [['amount' => 500]],
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        DB::table('relay_snapshots')->where('school_id', $school->id)->where('student_public_id', $studentId)->update([
            'payload' => $json,
            'payload_bytes' => strlen($json),
            'content_hash' => hash('sha256', $json),
        ]);
        DB::table('relay_parent_links')->insert([
            'school_id' => $school->id,
            'user_id' => $parent->id,
            'student_public_id' => $studentId,
            'access_level' => 'schedule_only',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $calendarEvent = [
            'id' => 12,
            'title' => 'School closed',
            'eventType' => 'closure',
            'startDate' => '2026-12-01',
            'endDate' => '2026-12-02',
            'notes' => 'Winter break',
        ];
        $calendarPayload = json_encode([$calendarEvent], JSON_THROW_ON_ERROR);
        DB::table('relay_school_calendars')->insert([
            'school_id' => $school->id,
            'revision' => 1,
            'content_hash' => hash('sha256', $calendarPayload),
            'payload' => $calendarPayload,
            'projected_at' => now(),
        ]);
        $unlinkedSchool = School::factory()->state(['name' => 'Unlinked Calendar School'])->create();
        $privatePayload = json_encode([[
            ...$calendarEvent,
            'title' => 'Private school event',
        ]], JSON_THROW_ON_ERROR);
        DB::table('relay_school_calendars')->insert([
            'school_id' => $unlinkedSchool->id,
            'revision' => 1,
            'content_hash' => hash('sha256', $privatePayload),
            'payload' => $privatePayload,
            'projected_at' => now(),
        ]);
        $token = $this->login($parent);

        $children = $this->withToken($token)->getJson('/api/v1/parent/children')->assertOk()
            ->assertJsonCount(1, 'children')
            ->assertJsonPath('children.0.accessLevel', 'schedule_only')
            ->assertJsonPath('children.0.snapshot.student.fullName', 'Schedule Child')
            ->assertJsonPath('children.0.snapshot.student.className', 'Class 3')
            ->assertJsonPath('children.0.snapshot.timetable.0.subjectName', 'Mathematics')
            ->assertJsonPath('children.0.snapshot.timetable.0.teacherName', 'Rina Patel')
            ->assertJsonCount(1, 'calendarEvents')
            ->assertJsonPath('calendarEvents.0.title', 'School closed')
            ->assertJsonPath('calendarEvents.0.schoolName', 'Schedule School')
            ->assertJsonMissingPath('children.0.snapshot.student.admissionNo')
            ->assertJsonMissingPath('children.0.snapshot.attendance')
            ->assertJsonMissingPath('children.0.snapshot.homework')
            ->assertJsonMissingPath('children.0.snapshot.announcements')
            ->assertJsonMissingPath('children.0.snapshot.results')
            ->assertJsonMissingPath('children.0.snapshot.fees');
        $this->withToken($token)->getJson("/api/v1/parent/children/{$studentId}")
            ->assertOk()->assertJsonMissingPath('snapshot.fees')
            ->assertJsonPath('calendarEvents.0.title', 'School closed');
        $this->withToken($token)->postJson('/api/v1/parent/commands', [
            'operation_id' => (string) Str::uuid(),
            'student_public_id' => $studentId,
            'operation' => 'parent_request.submit',
            'payload' => ['request_type' => 'leave', 'message' => 'Appointment'],
        ])->assertNotFound();
        $this->assertSame('schedule_only', DB::table('relay_parent_links')->value('access_level'));
    }

    public function test_one_verified_parent_account_can_access_linked_children_across_schools_and_manage_all_sessions(): void
    {
        $firstSchool = $this->createSchool('Aster School', 'aster-admin@example.test');
        $secondSchool = School::factory()->state(['name' => 'Birch School'])->create();
        $firstParent = $this->createParent($firstSchool, 'family@example.test');
        $secondParent = $this->createParent($secondSchool, 'family@example.test');
        $firstParent->forceFill(['phone' => '9876543210'])->save();
        $secondParent->forceFill(['phone' => '9876543210'])->save();
        $firstChild = (string) Str::uuid();
        $secondChild = (string) Str::uuid();
        $this->addSnapshot($firstSchool, $firstChild, 'Aster Child');
        $this->addSnapshot($secondSchool, $secondChild, 'Birch Child');
        foreach ([[$firstSchool, 'Aster concert'], [$secondSchool, 'Birch holiday']] as [$school, $title]) {
            $calendarPayload = json_encode([[
                'id' => 1,
                'title' => $title,
                'eventType' => 'event',
                'startDate' => '2026-11-20',
                'endDate' => null,
                'notes' => null,
            ]], JSON_THROW_ON_ERROR);
            DB::table('relay_school_calendars')->insert([
                'school_id' => $school->id,
                'revision' => 1,
                'content_hash' => hash('sha256', $calendarPayload),
                'payload' => $calendarPayload,
                'projected_at' => now(),
            ]);
        }
        foreach ([[$firstSchool, $firstParent, $firstChild], [$secondSchool, $secondParent, $secondChild]] as [$school, $parent, $studentId]) {
            DB::table('relay_parent_links')->insert([
                'school_id' => $school->id,
                'user_id' => $parent->id,
                'student_public_id' => $studentId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->mock(GoogleIdentityVerifier::class, fn ($mock) => $mock->shouldReceive('verify')->twice()->andReturn([
            'sub' => 'verified-family-google-subject',
            'email' => 'family@example.test',
            'email_verified' => true,
        ]));
        $login = $this->postJson('/api/v1/auth/google-login', [
            'email' => 'family@example.test',
            'phone' => '9876543210',
            'role' => 'parent',
            'google_id_token' => 'verified-family-token',
            'device_name' => 'Family Hub Parent · Android · first',
        ])->assertOk()->assertJsonMissingPath('requiresSchoolSelection');
        $token = $login->json('token');
        $this->assertDatabaseHas('users', ['id' => $firstParent->id, 'google_subject' => 'verified-family-google-subject']);
        $this->assertDatabaseHas('users', ['id' => $secondParent->id, 'google_subject' => 'verified-family-google-subject']);

        $children = $this->withToken($token)->getJson('/api/v1/parent/children')->assertOk()->assertJsonCount(2, 'children');
        $this->assertEqualsCanonicalizing(
            ['Aster concert', 'Birch holiday'],
            collect($children->json('calendarEvents'))->pluck('title')->all(),
        );
        $this->assertNotEmpty($children->json('calendarProjectedAtBySchool.'.$firstSchool->id));
        $this->assertEqualsCanonicalizing(
            ['Aster School', 'Birch School'],
            collect($children->json('children'))->pluck('schoolName')->all(),
        );
        $this->withToken($token)->getJson("/api/v1/parent/children/{$secondChild}")
            ->assertOk()->assertJsonPath('schoolName', 'Birch School');

        $secondSchoolLogin = $this->postJson('/api/v1/auth/google-login', [
            'email' => 'family@example.test',
            'phone' => '9876543210',
            'role' => 'parent',
            'school_id' => $secondSchool->id,
            'google_id_token' => 'verified-family-token',
            'device_name' => 'Family Hub Parent · Android · second',
        ])->assertOk();
        $secondSchoolToken = $secondSchoolLogin->json('token');
        $this->withToken($token)->postJson('/api/v1/parent/notification-preferences', [
            'category' => 'schedule_update',
            'enabled' => false,
        ])->assertOk();
        $this->assertDatabaseHas('parent_notification_preferences', [
            'user_id' => $firstParent->id,
            'category' => 'schedule_update',
            'enabled' => false,
        ]);
        $this->assertDatabaseHas('parent_notification_preferences', [
            'user_id' => $secondParent->id,
            'category' => 'schedule_update',
            'enabled' => false,
        ]);
        $this->withToken($secondSchoolToken)->getJson('/api/v1/parent/notification-preferences')
            ->assertOk()->assertJsonPath('preferences.schedule_update', false);
        $sessions = $this->withToken($token)->getJson('/api/v1/auth/sessions')->assertOk();
        $this->assertCount(2, $sessions->json('sessions'));
        $secondSession = collect($sessions->json('sessions'))->firstWhere('deviceName', 'Family Hub Parent · Android · second');
        $this->assertNotNull($secondSession);
        $this->withToken($token)->deleteJson('/api/v1/auth/sessions/'.$secondSession['id'])->assertOk()->assertJsonPath('signedOut', false);
        $this->withToken($secondSchoolToken)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $operationId = (string) Str::uuid();
        $this->withToken($token)->postJson('/api/v1/parent/commands', [
            'operation_id' => $operationId,
            'student_public_id' => $secondChild,
            'operation' => 'parent_request.submit',
            'payload' => ['request_type' => 'leave', 'message' => 'Family appointment'],
        ])->assertAccepted();
        $this->assertDatabaseHas('relay_parent_commands', [
            'school_id' => $secondSchool->id,
            'user_id' => $secondParent->id,
            'operation_id' => $operationId,
        ]);
    }

    public function test_parent_feature_flags_are_exposed_only_from_verified_license_claims(): void
    {
        $school = $this->createSchool('North School', 'north-admin@example.test');
        $parent = $this->createParent($school, 'parent@example.test');
        $license = $school->currentLicense;
        $claims = $license->claims;
        $claims['feature_flags'] = ['parent.transport', 'unknown.feature'];
        $license->forceFill([
            'claims' => $claims,
            'signed_token' => app(LicenseTokenService::class)->sign($claims),
        ])->save();
        $token = $this->login($parent);

        $this->withToken($token)->getJson('/api/v1/parent/children')->assertOk()
            ->assertJsonPath('parentFeatures.courses', false)
            ->assertJsonPath('parentFeatures.transport', true)
            ->assertJsonPath('parentFeatures.ai', false);
    }

    public function test_parent_request_is_queued_idempotently_and_cannot_target_an_unlinked_student(): void
    {
        $school = $this->createSchool('North School', 'north-admin@example.test');
        $parent = $this->createParent($school, 'parent@example.test');
        $linked = (string) Str::uuid();
        $unlinked = (string) Str::uuid();
        $this->addSnapshot($school, $linked, 'Child', withAttendance: true);
        $this->addSnapshot($school, $unlinked, 'Other Child');
        $snapshotBeforeRequest = DB::table('relay_snapshots')->where('school_id', $school->id)->where('student_public_id', $linked)->value('payload');
        DB::table('relay_parent_links')->insert(['school_id' => $school->id, 'user_id' => $parent->id, 'student_public_id' => $linked, 'created_at' => now(), 'updated_at' => now()]);
        $token = $this->login($parent);
        $operationId = (string) Str::uuid();
        $payload = ['operation_id' => $operationId, 'student_public_id' => $linked, 'operation' => 'parent_request.submit', 'payload' => ['request_type' => 'leave', 'starts_on' => '2026-10-25', 'ends_on' => '2026-10-26', 'message' => 'Family appointment']];

        $first = $this->withToken($token)->postJson('/api/v1/parent/commands', $payload)->assertAccepted()->assertJsonPath('status', 'queued');
        $this->withToken($token)->postJson('/api/v1/parent/commands', $payload)->assertOk()->assertJsonPath('operationId', $operationId);
        $this->withToken($token)->postJson('/api/v1/parent/commands', [
            ...$payload,
            'payload' => [...$payload['payload'], 'message' => 'A different request'],
        ])->assertConflict();
        $this->withToken($token)->postJson('/api/v1/parent/commands', [
            ...$payload,
            'payload' => [...$payload['payload'], 'unexpected' => 'unvalidated data'],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('relay_parent_commands', 1);
        $this->assertSame($snapshotBeforeRequest, DB::table('relay_snapshots')->where('school_id', $school->id)->where('student_public_id', $linked)->value('payload'));
        $this->assertSame('present', json_decode($snapshotBeforeRequest, true, flags: JSON_THROW_ON_ERROR)['attendance'][0]['status']);
        $this->withToken($token)->postJson('/api/v1/parent/commands', [...$payload, 'operation_id' => (string) Str::uuid(), 'student_public_id' => $unlinked])->assertNotFound();
        $this->assertDatabaseHas('relay_parent_commands', ['school_id' => $school->id, 'operation_id' => $operationId, 'status' => 'queued']);
        $this->withToken($token)->getJson('/api/v1/parent/commands')->assertOk()->assertJsonPath('commands.0.status', 'queued')->assertJsonPath('commands.0.operationId', $operationId);

        $privacyOperationId = (string) Str::uuid();
        $this->withToken($token)->postJson('/api/v1/parent/commands', [
            'operation_id' => $privacyOperationId,
            'student_public_id' => $linked,
            'operation' => 'parent_request.submit',
            'payload' => [
                'request_type' => 'data_deletion',
                'message' => 'Please review my child data deletion request.',
            ],
        ])->assertAccepted()->assertJsonPath('status', 'queued');
        $this->assertDatabaseHas('relay_parent_commands', [
            'school_id' => $school->id,
            'operation_id' => $privacyOperationId,
            'status' => 'queued',
        ]);
        $this->withToken($token)->postJson('/api/v1/parent/commands', [
            'operation_id' => (string) Str::uuid(),
            'student_public_id' => $linked,
            'operation' => 'parent_request.submit',
            'payload' => ['request_type' => 'delete_all', 'message' => 'Invalid type'],
        ])->assertUnprocessable();
    }

    public function test_teacher_cannot_read_parent_snapshots(): void
    {
        $school = $this->createSchool('North School', 'north-admin@example.test');
        $teacher = User::factory()->for($school)->state(['name' => 'Teacher', 'email' => 'teacher@example.test', 'password' => 'teacher-password-long', 'role' => 'teacher', 'is_active' => true])->create();

        $token = $teacher->createToken('test', ['teacher'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/parent/children')->assertForbidden();
    }

    private function createSchool(string $name, string $email): School
    {
        $this->postJson('/api/v1/setup/complete', ['school_name' => $name, 'admin_name' => 'School Admin', 'email' => $email, 'password' => 'a-long-test-password'])->assertCreated();

        return School::query()->where('name', $name)->firstOrFail();
    }

    private function createParent(School $school, string $email): User
    {
        return User::factory()->for($school)->state(['name' => 'Parent Account', 'email' => $email, 'phone' => '9876543210', 'password' => 'parent-password-long', 'role' => 'parent', 'is_active' => true])->create();
    }

    private function addSnapshot(School $school, string $studentId, string $name, bool $withAttendance = false): void
    {
        $payload = ['student' => ['fullName' => $name], 'attendance' => $withAttendance ? [['date' => '2026-10-24', 'status' => 'present']] : [], 'timetable' => [], 'homework' => [], 'announcements' => [], 'results' => [], 'fees' => []];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        DB::table('relay_snapshots')->insert(['school_id' => $school->id, 'student_public_id' => $studentId, 'revision' => 1, 'content_hash' => hash('sha256', $json), 'payload' => $json, 'payload_bytes' => strlen($json), 'projected_at' => now()]);
    }

    private function login(User $user): string
    {
        return $user->createToken('test', [$user->role])->plainTextToken;
    }
}
