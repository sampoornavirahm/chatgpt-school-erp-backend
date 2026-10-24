<?php

namespace Tests\Feature;

use App\Models\LicensePlan;
use App\Models\School;
use App\Models\User;
use App\Services\FcmSender;
use App\Services\SchoolLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RelaySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school_erp.trial_enabled' => true]);
    }

    public function test_signed_push_persists_a_student_snapshot_and_replays_idempotently(): void
    {
        $school = $this->createSchool();
        $this->addRelayCredential($school);
        $studentId = (string) Str::uuid();
        $body = $this->snapshotBatch((string) Str::uuid(), $studentId, 1, 'Asha Rao');
        $uri = "/api/v1/relay/schools/{$school->id}/sync/push";

        $first = $this->signedJson('POST', $uri, $body)->assertOk();
        $first->assertJsonPath('results.0.status', 'accepted');
        $this->assertDatabaseHas('relay_snapshots', ['school_id' => $school->id, 'student_public_id' => $studentId, 'revision' => 1]);
        $this->signedJson('POST', $uri, $body)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseCount('relay_snapshots', 1);
        $this->assertDatabaseCount('relay_batches', 1);
    }

    public function test_signed_account_sync_stores_contacts_links_multiple_children_and_revokes_disabled_accounts(): void
    {
        $school = $this->createSchool();
        $credential = $this->addRelayCredential($school);
        $firstStudent = (string) Str::uuid();
        $secondStudent = (string) Str::uuid();
        $uri = "/api/v1/relay/schools/{$school->id}/accounts/sync";
        $payload = [
            'accounts' => [[
                'relay_user_id' => 41,
                'name' => 'Parent One',
                'email' => 'PARENT@example.test',
                'phone' => '+91 98765-43210',
                'role' => 'parent',
                'is_active' => true,
                'student_public_ids' => [$firstStudent, $secondStudent],
                'student_access' => [
                    ['student_public_id' => $firstStudent, 'access_level' => 'full'],
                    ['student_public_id' => $secondStudent, 'access_level' => 'schedule_only'],
                ],
            ]],
        ];

        $this->signedJson('POST', $uri, $payload, $credential)->assertOk()
            ->assertJsonPath('results.0.status', 'accepted');
        $parent = User::query()->where('school_id', $school->id)->where('relay_user_id', 41)->sole();
        $this->assertSame('parent@example.test', $parent->email);
        $this->assertSame('919876543210', $parent->phone);
        $this->assertDatabaseCount('relay_parent_links', 2);
        $this->assertDatabaseHas('relay_parent_links', [
            'user_id' => $parent->id,
            'student_public_id' => $firstStudent,
            'access_level' => 'full',
        ]);
        $this->assertDatabaseHas('relay_parent_links', [
            'user_id' => $parent->id,
            'student_public_id' => $secondStudent,
            'access_level' => 'schedule_only',
        ]);
        $payload['accounts'][0]['student_access'][1]['access_level'] = 'full';
        $this->signedJson('POST', $uri, $payload, $credential)->assertOk();
        $this->assertDatabaseHas('relay_parent_links', [
            'user_id' => $parent->id,
            'student_public_id' => $secondStudent,
            'access_level' => 'full',
        ]);
        $parent->createToken('mobile-session', ['parent']);

        $payload['accounts'][0]['is_active'] = false;
        $this->signedJson('POST', $uri, $payload, $credential)->assertOk()
            ->assertJsonPath('results.0.status', 'accepted');
        $this->assertDatabaseHas('users', ['id' => $parent->id, 'is_active' => false]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $parent->id,
        ]);
    }

    public function test_new_published_notice_sends_one_generic_push_to_linked_parents_after_commit(): void
    {
        $school = $this->createSchool();
        $this->addRelayCredential($school);
        $parent = User::factory()->for($school)->state([
            'name' => 'Parent',
            'email' => 'notice-parent@example.test',
            'password' => 'parent-password-long',
            'role' => 'parent',
            'is_active' => true,
        ])->create();
        $studentId = (string) Str::uuid();
        DB::table('relay_parent_links')->insert([
            'school_id' => $school->id,
            'user_id' => $parent->id,
            'student_public_id' => $studentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->mock(FcmSender::class, function ($mock) use ($school, $parent): void {
            $mock->shouldReceive('sendSchoolNotice')->once()->with($school->id, [$parent->id])->andReturn(1);
        });
        $uri = "/api/v1/relay/schools/{$school->id}/sync/push";
        $first = $this->snapshotBatch((string) Str::uuid(), $studentId, 1, 'Asha Rao');
        $notice = ['id' => 25, 'title' => 'Term dates', 'message' => 'The term ends on Friday.', 'publishOn' => '2026-10-21'];
        $second = $this->snapshotBatch((string) Str::uuid(), $studentId, 2, 'Asha Rao', [$notice]);

        $this->signedJson('POST', $uri, $first)->assertOk();
        $this->signedJson('POST', $uri, $second)->assertOk();
        $this->signedJson('POST', $uri, $second)->assertOk()->assertHeader('Idempotency-Replayed', 'true');

        $school->forceFill(['storage_quota_bytes' => 1])->save();
        $rejectedNotice = $this->snapshotBatch(
            (string) Str::uuid(),
            $studentId,
            3,
            'Asha Rao',
            [['id' => 26, 'title' => 'Rejected notice', 'message' => 'Not stored']],
        );
        $this->signedJson('POST', $uri, $rejectedNotice)
            ->assertOk()
            ->assertJsonPath('results.0.code', 'SCHOOL_STORAGE_QUOTA_EXCEEDED');
        $this->app->terminate();
    }

    public function test_new_published_homework_notifies_linked_parent_once_and_batch_replay_does_not_repeat_it(): void
    {
        $school = $this->createSchool();
        $this->addRelayCredential($school);
        $parent = User::factory()->for($school)->state([
            'name' => 'Homework Parent',
            'email' => 'homework-parent@example.test',
            'password' => 'parent-password-long',
            'role' => 'parent',
            'is_active' => true,
        ])->create();
        $studentId = (string) Str::uuid();
        DB::table('relay_parent_links')->insert([
            'school_id' => $school->id,
            'user_id' => $parent->id,
            'student_public_id' => $studentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->mock(FcmSender::class, function ($mock) use ($school, $parent): void {
            $mock->shouldReceive('sendHomeworkUpdate')
                ->twice()
                ->with($school->id, [$parent->id])
                ->andReturn(1);
            $mock->shouldNotReceive('sendScheduleUpdate');
        });

        $uri = "/api/v1/relay/schools/{$school->id}/sync/push";
        $snapshot = function (int $revision, array $homework) use ($studentId): array {
            $body = $this->snapshotBatch((string) Str::uuid(), $studentId, $revision, 'Asha Rao');
            $body['snapshots'][0]['snapshot']['homework'] = $homework;

            return $body;
        };
        $initial = $snapshot(1, []);
        $firstAssignment = ['id' => 71, 'title' => 'Read chapter one', 'dueOn' => '2026-11-01'];
        $published = $snapshot(2, [$firstAssignment]);

        $this->signedJson('POST', $uri, $initial)->assertOk();
        $this->signedJson('POST', $uri, $published)->assertOk();
        $this->signedJson('POST', $uri, $published)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $updatedExisting = $firstAssignment;
        $updatedExisting['title'] = 'Read chapter one and answer the questions';
        $this->signedJson('POST', $uri, $snapshot(3, [$updatedExisting]))->assertOk();
        $secondAssignment = ['id' => 72, 'title' => 'Bring a notebook', 'dueOn' => '2026-11-02'];
        $this->signedJson('POST', $uri, $snapshot(4, [$updatedExisting, $secondAssignment]))->assertOk();

        $this->app->terminate();
    }

    public function test_school_calendar_projection_is_revisioned_idempotent_and_notifies_active_families(): void
    {
        $school = $this->createSchool();
        $credential = $this->addRelayCredential($school);
        $parent = User::factory()->for($school)->state([
            'name' => 'Calendar Parent',
            'email' => 'calendar-parent@example.test',
            'password' => 'parent-password-long',
            'role' => 'parent',
            'is_active' => true,
        ])->create();
        $teacher = User::factory()->for($school)->state([
            'name' => 'Calendar Teacher',
            'email' => 'calendar-teacher@example.test',
            'password' => 'teacher-password-long',
            'role' => 'teacher',
            'is_active' => true,
        ])->create();
        $this->mock(FcmSender::class, function ($mock) use ($school, $parent, $teacher): void {
            $mock->shouldReceive('sendScheduleUpdate')
                ->once()
                ->with($school->id, [$parent->id, $teacher->id])
                ->andReturn(2);
        });

        $uri = "/api/v1/relay/schools/{$school->id}/calendar";
        $events = [
            [
                'id' => 8,
                'title' => 'Term begins',
                'eventType' => 'event',
                'startDate' => '2026-11-02',
                'endDate' => null,
                'notes' => 'Classes resume',
            ],
            [
                'id' => 9,
                'title' => 'Grade 5 field trip',
                'eventType' => 'trip',
                'startDate' => '2026-11-12',
                'endDate' => '2026-11-12',
                'notes' => 'Permission slips required',
            ],
            [
                'id' => 10,
                'title' => 'Science project submission',
                'eventType' => 'deadline',
                'startDate' => '2026-11-14',
                'endDate' => null,
                'notes' => 'Submit to class teacher',
            ],
        ];
        $calendarBody = static fn (int $revision, array $calendarEvents): array => [
            'revision' => $revision,
            'content_hash' => hash('sha256', json_encode($calendarEvents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'events' => $calendarEvents,
        ];

        $this->signedJson('POST', $uri, $calendarBody(1, $events), $credential)
            ->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('revision', 1);
        $this->signedJson('POST', $uri, $calendarBody(1, $events), $credential)
            ->assertOk()->assertJsonPath('status', 'unchanged');

        $changedEvents = $events;
        $changedEvents[0]['notes'] = 'Classes resume at 8 AM';
        $this->signedJson('POST', $uri, $calendarBody(2, $changedEvents), $credential)
            ->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('revision', 2);
        $this->signedJson('POST', $uri, $calendarBody(1, $events), $credential)
            ->assertConflict()->assertJsonPath('status', 'stale');
        $conflictingEvents = $changedEvents;
        $conflictingEvents[0]['title'] = 'Conflicting title';
        $this->signedJson('POST', $uri, $calendarBody(2, $conflictingEvents), $credential)
            ->assertConflict()->assertJsonPath('error.code', 'CALENDAR_REVISION_CONFLICT');

        $this->assertDatabaseHas('relay_school_calendars', [
            'school_id' => $school->id,
            'revision' => 2,
            'content_hash' => $calendarBody(2, $changedEvents)['content_hash'],
        ]);
        $this->app->terminate();
    }

    public function test_relay_rejects_tampered_and_replayed_signatures_and_other_tenant_credentials(): void
    {
        $school = $this->createSchool();
        $credential = $this->addRelayCredential($school);
        $body = $this->snapshotBatch((string) Str::uuid(), (string) Str::uuid(), 1, 'Test');
        $content = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $uri = "/api/v1/relay/schools/{$school->id}/sync/push";
        $nonce = Str::random(32);
        $headers = $this->signedHeaders('POST', $credential['key_id'], $credential['secret'], $uri, $content, $nonce);

        $this->call('POST', $uri, [], [], [], $headers, $content)->assertOk();
        $this->call('POST', $uri, [], [], [], $headers, $content)->assertConflict()->assertJsonPath('error.code', 'RELAY_REPLAY_REJECTED');

        $changedContent = json_encode($this->snapshotBatch((string) Str::uuid(), (string) Str::uuid(), 1, 'Different'), JSON_THROW_ON_ERROR);
        $tampered = $this->signedHeaders('POST', $credential['key_id'], $credential['secret'], $uri, $content, Str::random(32));
        $this->call('POST', $uri, [], [], [], $tampered, $changedContent)->assertUnauthorized();

        $otherSchool = School::query()->forceCreate(['id' => (string) Str::uuid(), 'name' => 'Other School', 'server_id' => (string) Str::uuid()]);
        $this->signedJson('GET', "/api/v1/relay/schools/{$otherSchool->id}/sync/pull?cursor=0", null, $credential)->assertUnauthorized();
    }

    public function test_relay_preserves_newer_revisions_and_enforces_the_school_storage_limit(): void
    {
        $school = $this->createSchool();
        $this->addRelayCredential($school);
        $school->forceFill(['storage_quota_bytes' => 300])->save();
        $studentId = (string) Str::uuid();
        $this->signedJson('POST', "/api/v1/relay/schools/{$school->id}/sync/push", $this->snapshotBatch((string) Str::uuid(), $studentId, 2, 'Asha'))->assertOk();
        $this->signedJson('POST', "/api/v1/relay/schools/{$school->id}/sync/push", $this->snapshotBatch((string) Str::uuid(), $studentId, 1, 'Older'))->assertOk()->assertJsonPath('results.0.status', 'stale');
        $this->signedJson('POST', "/api/v1/relay/schools/{$school->id}/sync/push", $this->snapshotBatch((string) Str::uuid(), (string) Str::uuid(), 1, str_repeat('x', 1000)))->assertOk()->assertJsonPath('results.0.code', 'SCHOOL_STORAGE_QUOTA_EXCEEDED');
        $this->assertDatabaseHas('relay_snapshots', ['school_id' => $school->id, 'student_public_id' => $studentId, 'revision' => 2]);
    }

    public function test_relay_enforces_signed_student_seats_but_allows_deletion_and_reactivation(): void
    {
        $school = $this->createSchool();
        $this->addRelayCredential($school);
        $plan = LicensePlan::query()->where('key', 'professional_school')->firstOrFail();
        app(SchoolLicenseService::class)->issue($school, $plan, 1, null, now()->addYear(), null);
        $firstStudent = (string) Str::uuid();
        $secondStudent = (string) Str::uuid();

        $this->signedJson('POST', "/api/v1/relay/schools/{$school->id}/sync/push", $this->snapshotBatch((string) Str::uuid(), $firstStudent, 1, 'First Student'))
            ->assertOk()->assertJsonPath('results.0.status', 'accepted');
        $this->signedJson('POST', "/api/v1/relay/schools/{$school->id}/sync/push", $this->snapshotBatch((string) Str::uuid(), $secondStudent, 1, 'Second Student'))
            ->assertOk()->assertJsonPath('results.0.status', 'rejected')->assertJsonPath('results.0.code', 'SCHOOL_STUDENT_CAPACITY_EXCEEDED');

        $this->signedJson('POST', "/api/v1/relay/schools/{$school->id}/sync/push", [
            'batch_id' => (string) Str::uuid(),
            'snapshots' => [['student_public_id' => $firstStudent, 'revision' => 2, 'snapshot' => ['deleted' => true]]],
        ])->assertOk()->assertJsonPath('results.0.status', 'accepted');
        $this->signedJson('POST', "/api/v1/relay/schools/{$school->id}/sync/push", $this->snapshotBatch((string) Str::uuid(), $secondStudent, 1, 'Second Student'))
            ->assertOk()->assertJsonPath('results.0.status', 'accepted');
        $this->assertDatabaseHas('relay_snapshots', ['school_id' => $school->id, 'student_public_id' => $firstStudent, 'is_deleted' => true]);
        $this->assertDatabaseHas('relay_snapshots', ['school_id' => $school->id, 'student_public_id' => $secondStudent, 'is_deleted' => false]);
    }

    public function test_basic_adware_enforces_starter_student_limit_by_default(): void
    {
        $school = $this->createSchool();
        $this->addRelayCredential($school);
        $plan = LicensePlan::query()->where('key', 'basic_adware')->firstOrFail();
        app(SchoolLicenseService::class)->issue($school, $plan, null, null, null, null);

        for ($index = 0; $index < 100; $index++) {
            $studentId = (string) Str::uuid();
            DB::table('relay_snapshots')->insert([
                'school_id' => $school->id,
                'student_public_id' => $studentId,
                'revision' => 1,
                'content_hash' => hash('sha256', $studentId),
                'payload' => '{"student":{"fullName":"Seeded Student"}}',
                'payload_bytes' => 36,
                'is_deleted' => false,
                'projected_at' => now(),
            ]);
        }

        $this->signedJson('POST', "/api/v1/relay/schools/{$school->id}/sync/push", $this->snapshotBatch((string) Str::uuid(), (string) Str::uuid(), 1, 'Student 101'))
            ->assertOk()->assertJsonPath('results.0.status', 'rejected')->assertJsonPath('results.0.code', 'SCHOOL_STUDENT_CAPACITY_EXCEEDED');
    }

    public function test_relay_writes_stop_after_grace_and_on_license_revocation(): void
    {
        $school = $this->createSchool();
        $this->addRelayCredential($school);
        $this->travelTo($school->trial_expires_at->copy()->addDays(8));

        $this->signedJson('POST', "/api/v1/relay/schools/{$school->id}/sync/push", $this->snapshotBatch((string) Str::uuid(), (string) Str::uuid(), 1, 'Expired Student'))
            ->assertForbidden()->assertJsonPath('error.code', 'LICENSE_EXPIRED_READ_ONLY');

        $operator = User::factory()->create(['role' => 'platform_admin', 'is_active' => true]);
        $this->actingAs($operator)->post(route('operator.schools.licenses.revoke', [$school, $school->currentLicense]))->assertRedirect();
        $this->signedJson('POST', "/api/v1/relay/schools/{$school->id}/sync/push", $this->snapshotBatch((string) Str::uuid(), (string) Str::uuid(), 1, 'Revoked Student'))
            ->assertForbidden()->assertJsonPath('error.code', 'LICENSE_REVOKED_READ_ONLY');
        $this->assertDatabaseCount('relay_snapshots', 0);
    }

    public function test_signed_pull_delivers_parent_commands_and_accepts_hub_acknowledgement(): void
    {
        $school = $this->createSchool();
        $this->addRelayCredential($school);
        $parent = User::factory()->for($school)->state(['name' => 'Parent', 'email' => 'parent@example.test', 'password' => 'parent-password-long', 'role' => 'parent', 'is_active' => true])->create();
        $studentId = (string) Str::uuid();
        $operationId = (string) Str::uuid();
        DB::table('relay_parent_commands')->insert([
            'school_id' => $school->id, 'operation_id' => $operationId, 'user_id' => $parent->id,
            'student_public_id' => $studentId, 'operation' => 'parent_request.submit',
            'payload' => json_encode(['request_type' => 'leave', 'message' => 'Medical leave'], JSON_THROW_ON_ERROR),
            'status' => 'queued', 'created_at' => now(),
        ]);
        $this->signedJson('GET', "/api/v1/relay/schools/{$school->id}/sync/pull?cursor=0", null)
            ->assertOk()->assertJsonPath('commands.0.operationId', $operationId)->assertJsonPath('commands.0.studentPublicId', $studentId);
        $this->signedJson('POST', "/api/v1/relay/schools/{$school->id}/sync/ack", ['results' => [['operation_id' => $operationId, 'status' => 'accepted', 'message' => 'Request recorded']]])
            ->assertOk()->assertJsonPath('updated', 1);
        $this->assertDatabaseHas('relay_parent_commands', ['operation_id' => $operationId, 'status' => 'accepted']);
        $this->signedJson('POST', "/api/v1/relay/schools/{$school->id}/sync/ack", ['results' => [['operation_id' => $operationId, 'status' => 'request_approved', 'message' => 'Approved by the school.']]])
            ->assertOk()->assertJsonPath('updated', 1);
        $this->assertDatabaseHas('relay_parent_commands', ['operation_id' => $operationId, 'status' => 'request_approved', 'result_message' => 'Approved by the school.']);
        $needsInfoOperation = (string) Str::uuid();
        DB::table('relay_parent_commands')->insert([
            'school_id' => $school->id, 'operation_id' => $needsInfoOperation, 'user_id' => $parent->id,
            'student_public_id' => $studentId, 'operation' => 'parent_request.submit',
            'payload' => json_encode(['request_type' => 'leave', 'message' => 'Medical leave'], JSON_THROW_ON_ERROR),
            'status' => 'accepted', 'created_at' => now(),
        ]);
        $this->signedJson('POST', "/api/v1/relay/schools/{$school->id}/sync/ack", ['results' => [['operation_id' => $needsInfoOperation, 'status' => 'needs_info', 'message' => 'Please add the return date.']]])
            ->assertOk()->assertJsonPath('updated', 1);
        $this->assertDatabaseHas('relay_parent_commands', ['operation_id' => $needsInfoOperation, 'status' => 'needs_info']);
        $rejectedOperation = (string) Str::uuid();
        DB::table('relay_parent_commands')->insert([
            'school_id' => $school->id, 'operation_id' => $rejectedOperation, 'user_id' => $parent->id,
            'student_public_id' => $studentId, 'operation' => 'parent_request.submit',
            'payload' => json_encode(['request_type' => 'leave', 'message' => 'Medical leave'], JSON_THROW_ON_ERROR),
            'status' => 'accepted', 'created_at' => now(),
        ]);
        $this->signedJson('POST', "/api/v1/relay/schools/{$school->id}/sync/ack", ['results' => [['operation_id' => $rejectedOperation, 'status' => 'request_rejected', 'message' => 'The school declined this request.']]])
            ->assertOk()->assertJsonPath('updated', 1);
        $this->assertDatabaseHas('relay_parent_commands', ['operation_id' => $rejectedOperation, 'status' => 'request_rejected']);
        DB::table('relay_parent_links')->insert([
            'school_id' => $school->id, 'user_id' => $parent->id, 'student_public_id' => $studentId,
            'access_level' => 'full', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $parentToken = $parent->createToken('parent-status-test', ['parent'])->plainTextToken;
        $this->withToken($parentToken)->getJson('/api/v1/parent/commands')->assertOk()
            ->assertJsonFragment(['operationId' => $operationId, 'status' => 'request_approved', 'message' => 'Approved by the school.'])
            ->assertJsonFragment(['operationId' => $needsInfoOperation, 'status' => 'needs_info', 'message' => 'Please add the return date.'])
            ->assertJsonFragment(['operationId' => $rejectedOperation, 'status' => 'request_rejected', 'message' => 'The school declined this request.']);
    }

    public function test_operator_queues_a_main_account_password_change_for_the_signed_school_relay(): void
    {
        $school = $this->createSchool();
        $this->addRelayCredential($school);
        $operator = User::factory()->create(['role' => 'platform_admin', 'is_active' => true]);
        $newPassword = 'New-Primary-School-Password-2026';

        $this->actingAs($operator)->post(route('operator.schools.main-user-password', $school), [
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertRedirect()->assertSessionHas('status');

        $queued = DB::table('school_main_password_changes')->where('school_id', $school->id)->sole();
        $this->assertSame('pending', $queued->status);
        $this->assertSame($school->server_id, $queued->server_id);
        $this->assertNotSame($newPassword, $queued->password_ciphertext);
        $pullUri = "/api/v1/relay/schools/{$school->id}/main-password-change";
        $this->signedJson('GET', $pullUri, null)
            ->assertOk()
            ->assertJsonPath('change.id', $queued->id)
            ->assertJsonPath('change.password', $newPassword);

        $this->signedJson('POST', $pullUri.'/'.$queued->id.'/ack', ['status' => 'applied', 'message' => ''])
            ->assertOk()
            ->assertJsonPath('updated', 1);
        $this->assertDatabaseHas('school_main_password_changes', ['id' => $queued->id, 'status' => 'applied']);
        $this->assertSame('', DB::table('school_main_password_changes')->where('id', $queued->id)->value('password_ciphertext'));
        $this->assertDatabaseHas('relay_audit_logs', [
            'school_id' => $school->id,
            'action' => 'school_main_password_change_applied',
            'target_id' => $queued->id,
        ]);
    }

    public function test_operator_must_confirm_the_new_main_account_password(): void
    {
        $school = $this->createSchool();
        $operator = User::factory()->create(['role' => 'platform_admin', 'is_active' => true]);

        $this->actingAs($operator)->post(route('operator.schools.main-user-password', $school), [
            'password' => 'New-Primary-School-Password-2026',
            'password_confirmation' => 'A-different-password-2026',
        ])->assertRedirect()->assertSessionHasErrors('password');

        $this->assertDatabaseCount('school_main_password_changes', 0);
    }

    private function createSchool(): School
    {
        $this->postJson('/api/v1/setup/complete', ['school_name' => 'Example School', 'admin_name' => 'School Admin', 'email' => 'admin@example.test', 'password' => 'a-long-test-password'])->assertCreated();

        return School::query()->sole();
    }

    private function addRelayCredential(School $school): array
    {
        $credential = ['key_id' => (string) Str::uuid(), 'secret' => str_repeat('k', 64)];
        DB::table('school_relay_credentials')->insert(['school_id' => $school->id, 'key_id' => $credential['key_id'], 'secret_ciphertext' => Crypt::encryptString($credential['secret']), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $credential;
    }

    private function snapshotBatch(string $batchId, string $studentId, int $revision, string $name, array $announcements = []): array
    {
        return ['batch_id' => $batchId, 'snapshots' => [[
            'student_public_id' => $studentId, 'revision' => $revision,
            'snapshot' => ['student' => ['fullName' => $name], 'attendance' => [], 'timetable' => [], 'homework' => [], 'announcements' => $announcements, 'results' => [], 'fees' => []],
        ]]];
    }

    private function signedJson(string $method, string $uri, ?array $body, ?array $credential = null): TestResponse
    {
        $credential ??= get_object_vars(DB::table('school_relay_credentials')->first(['key_id', 'secret_ciphertext']));
        $secret = $credential['secret'] ?? Crypt::decryptString($credential['secret_ciphertext']);
        $content = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->call($method, $uri, [], [], [], $this->signedHeaders($method, $credential['key_id'], $secret, $uri, $content, Str::random(32)), $content);
    }

    private function signedHeaders(string $method, string $keyId, string $secret, string $uri, string $content, string $nonce): array
    {
        $timestamp = (string) now()->timestamp;
        $signedContent = implode("\n", [$method, $uri, $timestamp, $nonce, hash('sha256', $content)]);

        return ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RELAY_KEY' => $keyId, 'HTTP_X_RELAY_TIMESTAMP' => $timestamp, 'HTTP_X_RELAY_NONCE' => $nonce, 'HTTP_X_RELAY_SIGNATURE' => hash_hmac('sha256', $signedContent, $secret)];
    }
}
