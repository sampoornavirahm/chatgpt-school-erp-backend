<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
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
        $this->addSnapshot($school, $linked, 'Child');
        $this->addSnapshot($school, $unlinked, 'Other Child');
        DB::table('relay_parent_links')->insert(['school_id' => $school->id, 'user_id' => $parent->id, 'student_public_id' => $linked, 'created_at' => now(), 'updated_at' => now()]);
        $token = $this->login($parent);
        $operationId = (string) Str::uuid();
        $payload = ['operation_id' => $operationId, 'student_public_id' => $linked, 'operation' => 'parent_request.submit', 'payload' => ['request_type' => 'leave', 'starts_on' => '2026-10-25', 'ends_on' => '2026-10-26', 'message' => 'Family appointment']];

        $first = $this->withToken($token)->postJson('/api/v1/parent/commands', $payload)->assertAccepted()->assertJsonPath('status', 'queued');
        $this->withToken($token)->postJson('/api/v1/parent/commands', $payload)->assertOk()->assertJsonPath('operationId', $operationId);
        $this->assertDatabaseCount('relay_parent_commands', 1);
        $this->withToken($token)->postJson('/api/v1/parent/commands', [...$payload, 'operation_id' => (string) Str::uuid(), 'student_public_id' => $unlinked])->assertNotFound();
        $this->assertDatabaseHas('relay_parent_commands', ['school_id' => $school->id, 'operation_id' => $operationId, 'status' => 'queued']);
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

        return School::query()->sole();
    }

    private function createParent(School $school, string $email): User
    {
        return User::factory()->for($school)->state(['name' => 'Parent Account', 'email' => $email, 'password' => 'parent-password-long', 'role' => 'parent', 'is_active' => true])->create();
    }

    private function addSnapshot(School $school, string $studentId, string $name): void
    {
        $payload = ['student' => ['fullName' => $name], 'attendance' => [], 'timetable' => [], 'homework' => [], 'announcements' => [], 'results' => [], 'fees' => []];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        DB::table('relay_snapshots')->insert(['school_id' => $school->id, 'student_public_id' => $studentId, 'revision' => 1, 'content_hash' => hash('sha256', $json), 'payload' => $json, 'payload_bytes' => strlen($json), 'projected_at' => now()]);
    }

    private function login(User $user): string
    {
        return $user->createToken('test', [$user->role])->plainTextToken;
    }
}
