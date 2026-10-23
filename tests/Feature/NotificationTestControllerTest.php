<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\FcmSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NotificationTestControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school_erp.trial_enabled' => true]);
    }

    public function test_school_admin_can_send_a_generic_connection_test_push(): void
    {
        $setup = $this->createTrialSchool();
        $school = School::query()->sole();
        $this->mock(FcmSender::class, function ($mock) use ($school): void {
            $mock->shouldReceive('sendConnectionTest')->once()->with($school->id)->andReturn(1);
        });

        $this->withToken($setup->json('token'))->postJson('/api/v1/admin/notifications/test')
            ->assertOk()->assertJsonPath('sent', 1);
    }

    public function test_returns_503_when_fcm_server_credentials_are_missing(): void
    {
        config([
            'school_erp.firebase_project_id' => null,
            'school_erp.firebase_service_account' => null,
        ]);
        $setup = $this->createTrialSchool();

        $this->withToken($setup->json('token'))->postJson('/api/v1/admin/notifications/test')
            ->assertServiceUnavailable()->assertJsonPath('error.code', 'FCM_NOT_CONFIGURED');
    }

    public function test_teacher_gets_403_when_sending_school_wide_test_push(): void
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

        $this->withToken($token)->postJson('/api/v1/admin/notifications/test')
            ->assertForbidden();
    }

    private function createTrialSchool(): TestResponse
    {
        return $this->postJson('/api/v1/setup/complete', [
            'school_name' => 'Example School',
            'admin_name' => 'School Admin',
            'email' => 'admin@example.test',
            'password' => 'a-long-test-password',
        ])->assertCreated();
    }
}
