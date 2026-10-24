<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\GoogleIdentityVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperatorPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school_erp.trial_enabled' => true]);
    }

    public function test_bootstrap_token_creates_a_single_platform_operator(): void
    {
        config(['school_erp.platform_bootstrap_token' => str_repeat('b', 48)]);
        $this->get('/operator/bootstrap')->assertOk()->assertSee('One-time operator setup');
        $this->post('/operator/bootstrap', ['bootstrap_token' => 'wrong', 'name' => 'Platform Operator', 'email' => 'platform@example.test', 'password' => 'platform-admin-password-long'])->assertRedirect()->assertSessionHasErrors('bootstrap_token');
        $this->post('/operator/bootstrap', ['bootstrap_token' => str_repeat('b', 48), 'name' => 'Platform Operator', 'email' => 'platform@example.test', 'password' => 'platform-admin-password-long'])->assertRedirect(route('operator.login'));

        $operator = User::query()->where('role', 'platform_admin')->sole();
        $this->assertNull($operator->school_id);
        $this->assertTrue(Hash::check('platform-admin-password-long', $operator->password));
        $this->assertNotNull(DB::table('platform_bootstrap_state')->where('id', 1)->value('consumed_at'));
        $this->get('/operator/bootstrap')->assertNotFound();
    }

    public function test_school_admin_cannot_open_the_platform_operator_portal(): void
    {
        $this->postJson('/api/v1/setup/complete', ['school_name' => 'Example School', 'admin_name' => 'School Admin', 'email' => 'admin@example.test', 'password' => 'a-long-test-password'])->assertCreated();
        $schoolAdmin = User::query()->where('role', 'school_admin')->sole();

        $this->actingAs($schoolAdmin)->get('/operator/schools')->assertForbidden();
    }

    public function test_operator_can_view_audit_storage_and_explicit_parent_link_setup(): void
    {
        $this->postJson('/api/v1/setup/complete', ['school_name' => 'Example School', 'admin_name' => 'School Admin', 'email' => 'admin@example.test', 'password' => 'a-long-test-password'])->assertCreated();
        $school = School::query()->sole();
        $operator = User::query()->create(['school_id' => null, 'name' => 'Platform Operator', 'email' => 'platform@example.test', 'password' => 'platform-admin-password-long', 'role' => 'platform_admin', 'is_active' => true]);
        $studentId = (string) Str::uuid();
        $snapshot = json_encode(['student' => ['fullName' => 'Child Name'], 'attendance' => [], 'timetable' => [], 'homework' => [], 'announcements' => [], 'results' => [], 'fees' => []], JSON_THROW_ON_ERROR);
        DB::table('relay_snapshots')->insert(['school_id' => $school->id, 'student_public_id' => $studentId, 'revision' => 1, 'content_hash' => hash('sha256', $snapshot), 'payload' => $snapshot, 'payload_bytes' => strlen($snapshot), 'projected_at' => now()]);
        $parent = User::factory()->for($school)->state(['name' => 'Parent', 'email' => 'parent@example.test', 'password' => 'parent-password-long', 'role' => 'parent', 'is_active' => true])->create();
        $this->actingAs($operator)->post(route('operator.schools.parent-links.store', $school), ['user_id' => $parent->id, 'student_public_id' => $studentId])->assertRedirect();
        $this->assertDatabaseHas('relay_parent_links', ['school_id' => $school->id, 'user_id' => $parent->id, 'student_public_id' => $studentId]);
        $this->actingAs($operator)->get(route('operator.schools.show', $school))->assertOk()->assertSee('Child Name')->assertSee('Parent');
    }

    public function test_operator_creates_a_second_trial_tenant_and_parent_google_login_links_matching_school_accounts(): void
    {
        $this->postJson('/api/v1/setup/complete', ['school_name' => 'First School', 'admin_name' => 'School Admin', 'email' => 'admin@example.test', 'password' => 'a-long-test-password'])->assertCreated();
        $firstSchool = School::query()->sole();
        $operator = User::query()->create(['school_id' => null, 'name' => 'Platform Operator', 'email' => 'platform@example.test', 'password' => 'platform-admin-password-long', 'role' => 'platform_admin', 'is_active' => true]);
        $this->actingAs($operator)->post(route('operator.schools.store'), [
            'school_name' => 'Second School',
            'admin_name' => 'Second Admin',
            'admin_email' => 'second-admin@example.test',
            'admin_password' => 'second-admin-password-long',
        ])->assertRedirect();
        $secondSchool = School::query()->where('name', 'Second School')->sole();
        $firstParent = User::factory()->for($firstSchool)->state(['name' => 'First Parent', 'email' => 'parent@example.test', 'phone' => '9876543210', 'password' => 'parent-password-long', 'role' => 'parent', 'is_active' => true])->create();
        $secondParent = User::factory()->for($secondSchool)->state(['name' => 'Second Parent', 'email' => 'parent@example.test', 'phone' => '9876543210', 'password' => 'parent-password-long', 'role' => 'parent', 'is_active' => true])->create();
        foreach ([[$firstSchool, $firstParent], [$secondSchool, $secondParent]] as [$school, $parent]) {
            DB::table('relay_parent_links')->insert([
                'school_id' => $school->id,
                'user_id' => $parent->id,
                'student_public_id' => (string) Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->mock(GoogleIdentityVerifier::class, fn ($mock) => $mock->shouldReceive('verify')->once()->andReturn([
            'sub' => 'same-google-parent',
            'email' => 'parent@example.test',
            'email_verified' => true,
        ]));

        $basePayload = ['email' => 'parent@example.test', 'phone' => '9876543210', 'role' => 'parent', 'google_id_token' => 'signed-test-token'];
        $login = $this->postJson('/api/v1/auth/google-login', $basePayload)
            ->assertOk()->assertJsonMissingPath('requiresSchoolSelection');
        $this->assertDatabaseHas('users', ['id' => $firstParent->id, 'google_subject' => 'same-google-parent']);
        $this->assertDatabaseHas('users', ['id' => $secondParent->id, 'google_subject' => 'same-google-parent']);
        $this->assertContains($login->json('user.id'), [$firstParent->id, $secondParent->id]);
        $this->withToken($login->json('token'))->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('user.id', $login->json('user.id'));
    }
}
