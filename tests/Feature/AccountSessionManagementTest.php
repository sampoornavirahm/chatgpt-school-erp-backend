<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountSessionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school_erp.trial_enabled' => true]);
    }

    public function test_parent_can_review_and_revoke_only_their_own_sessions(): void
    {
        $school = $this->createTrialSchool();
        $parent = $this->createUser($school, 'parent', 'parent@example.test');
        $teacher = $this->createUser($school, 'teacher', 'teacher@example.test');
        $phone = $parent->createToken('Family Hub Android · 123456', ['parent']);
        $tablet = $parent->createToken('Family Hub Android · abcdef', ['parent']);
        $teacherSession = $teacher->createToken('Teacher tablet', ['teacher']);

        $listed = $this->withToken($phone->plainTextToken)
            ->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'sessions');
        $phoneSession = collect($listed->json('sessions'))
            ->firstWhere('id', (string) $phone->accessToken->id);
        $this->assertTrue($phoneSession['isCurrent']);

        $this->withToken($phone->plainTextToken)
            ->deleteJson('/api/v1/auth/sessions/'.$teacherSession->accessToken->id)
            ->assertNotFound();

        $this->withToken($phone->plainTextToken)
            ->deleteJson('/api/v1/auth/sessions/'.$tablet->accessToken->id)
            ->assertOk()
            ->assertJsonPath('revoked', true)
            ->assertJsonPath('signedOut', false);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $phone->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tablet->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $teacherSession->accessToken->id]);
    }

    public function test_account_owner_can_revoke_all_sessions_and_expired_sessions_are_not_listed(): void
    {
        $school = $this->createTrialSchool();
        $parent = $this->createUser($school, 'parent', 'parent@example.test');
        $phone = $parent->createToken('Family Hub Android', ['parent']);
        $oldDevice = $parent->createToken('Old phone', ['parent']);
        DB::table('personal_access_tokens')->where('id', $oldDevice->accessToken->id)
            ->update(['created_at' => now()->subMinutes(config('sanctum.expiration') + 1)]);

        $this->withToken($phone->plainTextToken)
            ->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'sessions');

        $this->withToken($phone->plainTextToken)
            ->deleteJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonPath('signed_out', true);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $phone->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldDevice->accessToken->id]);
        $this->withToken($phone->plainTextToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_school_admin_cannot_use_mobile_session_management(): void
    {
        $school = $this->createTrialSchool();
        $admin = $school->users()->where('role', 'school_admin')->firstOrFail();
        $token = $admin->createToken('operator console')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/sessions')->assertForbidden();
        $this->withToken($token)->deleteJson('/api/v1/auth/sessions')->assertForbidden();
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

    private function createUser(School $school, string $role, string $email): User
    {
        return User::factory()->for($school)->state([
            'name' => ucfirst($role).' Account',
            'email' => $email,
            'password' => 'account-password-long',
            'role' => $role,
            'is_active' => true,
        ])->create();
    }
}
