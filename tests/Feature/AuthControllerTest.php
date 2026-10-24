<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\GoogleIdentityVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school_erp.trial_enabled' => true]);
    }

    public function test_password_login_is_reserved_for_school_admin_accounts(): void
    {
        $school = $this->createTrialSchool();
        $teacher = $this->createTeacher($school);

        $this->postJson('/api/v1/auth/login', [
            'email' => $teacher->email,
            'password' => 'teacher-password-long',
            'role' => 'parent',
        ])->assertForbidden()->assertJsonPath('error.code', 'GOOGLE_SIGN_IN_REQUIRED');

        $admin = $school->users()->where('role', 'school_admin')->firstOrFail();
        $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'a-long-test-password',
            'role' => 'school_admin',
        ])->assertOk()->assertJsonPath('user.role', 'school_admin');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $teacher->email,
            'password' => 'teacher-password-long',
            'role' => 'teacher',
        ])->assertForbidden()->assertJsonPath('error.code', 'GOOGLE_SIGN_IN_REQUIRED');
    }

    public function test_api_token_is_rejected_after_the_configured_expiration(): void
    {
        $school = $this->createTrialSchool();
        $admin = $school->users()->where('role', 'school_admin')->firstOrFail();
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'a-long-test-password',
            'role' => 'school_admin',
        ])->assertOk();

        DB::table('personal_access_tokens')->update([
            'created_at' => now()->subMinutes(config('sanctum.expiration') + 1),
        ]);

        $this->withToken($login->json('token'))->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_disabling_a_mobile_account_invalidates_its_existing_token(): void
    {
        $school = $this->createTrialSchool();
        $parent = User::factory()->for($school)->state([
            'name' => 'Parent Account',
            'email' => 'parent@example.test',
            'phone' => '9876543210',
            'password' => 'parent-password-long',
            'role' => 'parent',
            'is_active' => true,
        ])->create();
        $token = $parent->createToken('parent-device', ['parent'])->plainTextToken;
        $parent->forceFill(['is_active' => false])->save();

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'ACCOUNT_DISABLED');
    }

    public function test_disabling_trial_blocks_bypassed_school_setup_with_403(): void
    {
        $setup = $this->postJson('/api/v1/setup/complete', [
            'school_name' => 'Example School',
            'admin_name' => 'School Admin',
            'email' => 'admin@example.test',
            'password' => 'a-long-test-password',
        ])->assertCreated();
        $teacher = $this->createTeacher(School::query()->sole());
        $this->mock(GoogleIdentityVerifier::class, fn ($mock) => $mock->shouldReceive('verify')->once()->andReturn([
            'sub' => 'google-teacher-subject',
            'email' => $teacher->email,
            'email_verified' => true,
        ]));

        config(['school_erp.trial_enabled' => false]);

        $this->getJson('/api/v1/setup/status')
            ->assertJsonPath('configured', true)
            ->assertJsonPath('access_ready', false);

        $this->postJson('/api/v1/auth/google-login', [
            'email' => $teacher->email,
            'phone' => $teacher->phone,
            'role' => 'teacher',
            'google_id_token' => 'verified-test-token',
        ])->assertForbidden()->assertJsonPath('error.code', 'SCHOOL_ACCESS_NOT_ACTIVE');

        $this->withToken($setup->json('token'))->getJson('/api/v1/auth/me')
            ->assertForbidden()->assertJsonPath('error.code', 'SCHOOL_ACCESS_NOT_ACTIVE');
    }

    private function createTrialSchool(): School
    {
        $this->postJson('/api/v1/setup/complete', [
            'school_name' => 'Example School',
            'admin_name' => 'School Admin',
            'email' => 'admin@example.test',
            'password' => 'a-long-test-password',
        ])->assertCreated();

        return School::query()->sole();
    }

    private function createTeacher(School $school): User
    {
        return User::factory()->for($school)->state([
            'name' => 'Sample Teacher',
            'email' => 'teacher@example.test',
            'phone' => '9876543210',
            'password' => 'teacher-password-long',
            'role' => 'teacher',
            'is_active' => true,
        ])->create();
    }
}
