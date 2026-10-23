<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school_erp.trial_enabled' => true]);
    }

    public function test_school_admin_creates_a_google_sign_in_parent_account_with_contact_details(): void
    {
        $this->createTrialSchool();
        $adminLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'a-long-test-password',
            'role' => 'school_admin',
        ])->assertOk()->assertJsonPath('user.role', 'school_admin');

        $response = $this->withToken($adminLogin->json('token'))->postJson('/api/v1/admin/users', [
            'name' => 'Sample Parent',
            'email' => 'parent@example.test',
            'phone' => '9876543210',
            'role' => 'parent',
        ])->assertCreated()->assertJsonPath('role', 'parent')->assertJsonMissingPath('password');

        $parent = User::query()->where('email', 'parent@example.test')->sole();
        $this->assertSame($parent->id, $response->json('id'));
        $this->assertSame('parent', $parent->role);
        $this->assertNotSame('parent-password-long', $parent->password);
        $this->assertSame('9876543210', $parent->phone);
    }

    public function test_teacher_gets_403_when_creating_mobile_accounts(): void
    {
        $this->createTrialSchool();
        $school = School::query()->sole();
        $teacher = User::factory()->for($school)->state([
            'name' => 'Sample Teacher',
            'email' => 'teacher@example.test',
            'password' => 'teacher-password-long',
            'role' => 'teacher',
            'is_active' => true,
        ])->create();
        $token = $teacher->createToken('test', ['teacher'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/admin/users', [
            'name' => 'Sample Parent',
            'email' => 'parent@example.test',
            'password' => 'parent-password-long',
            'role' => 'parent',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'parent@example.test']);
    }

    private function createTrialSchool(): void
    {
        $this->postJson('/api/v1/setup/complete', [
            'school_name' => 'Example School',
            'admin_name' => 'School Admin',
            'email' => 'admin@example.test',
            'password' => 'a-long-test-password',
        ])->assertCreated();
    }
}
