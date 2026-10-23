<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\FcmAccessTokenProvider;
use App\Services\FcmSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FcmSenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school_erp.trial_enabled' => true]);
    }

    public function test_notice_push_uses_a_generic_payload_and_only_targets_requested_school_users(): void
    {
        $school = School::factory()->create(['name' => 'Target School']);
        $parent = $this->createUser($school, 'parent@example.test', 'parent');
        $unrelatedUser = $this->createUser($school, 'staff@example.test', 'teacher');
        $otherSchool = School::factory()->create(['name' => 'Other School']);
        $otherParent = $this->createUser($otherSchool, 'other-parent@example.test', 'parent');

        $this->registerDevice($parent, 'parent-installation-123456789');
        $this->registerDevice($unrelatedUser, 'staff-installation-123456789');
        $this->registerDevice($otherParent, 'other-installation-123456789');

        $credentialsPath = base_path('tests/TestCase.php');
        config([
            'school_erp.firebase_project_id' => 'testproject1',
            'school_erp.firebase_service_account' => $credentialsPath,
        ]);
        $this->mock(FcmAccessTokenProvider::class, function ($mock) use ($credentialsPath): void {
            $mock->shouldReceive('accessToken')->once()->with(realpath($credentialsPath))->andReturn('test-access-token');
        });
        Http::fake([
            'https://fcm.googleapis.com/v1/projects/testproject1/messages:send' => Http::response(['name' => 'projects/testproject1/messages/test-id'], 200),
        ]);

        $sent = app(FcmSender::class)->sendSchoolNotice((string) $school->id, [$parent->id]);

        $this->assertSame(1, $sent);
        Http::assertSent(function (Request $request) use ($parent): bool {
            return $request->url() === 'https://fcm.googleapis.com/v1/projects/testproject1/messages:send'
                && $request->hasHeader('Authorization', 'Bearer test-access-token')
                && $request->data() === [
                    'message' => [
                        'fid' => 'parent-installation-123456789',
                        'notification' => [
                            'title' => 'School ERP',
                            'body' => 'A new school notice is available. Open School messages to read it.',
                        ],
                        'data' => ['type' => 'school_notice'],
                    ],
                ]
                && ! str_contains(json_encode($request->data(), JSON_THROW_ON_ERROR), (string) $parent->name);
        });
        Http::assertSentCount(1);
    }

    public function test_missing_firebase_configuration_leaves_notice_sync_available(): void
    {
        $school = School::factory()->create();
        config([
            'school_erp.firebase_project_id' => '',
            'school_erp.firebase_service_account' => '',
        ]);
        Log::shouldReceive('warning')->once()->with('Optional school notice push could not be sent.');

        $sent = app(FcmSender::class)->sendSchoolNotice((string) $school->id, [123]);

        $this->assertSame(0, $sent);
    }

    private function createUser(School $school, string $email, string $role): User
    {
        return User::factory()->for($school)->state([
            'name' => 'Synthetic '.$role,
            'email' => $email,
            'password' => 'user-password-long',
            'role' => $role,
            'is_active' => true,
        ])->create();
    }

    private function registerDevice(User $user, string $fid): void
    {
        DB::table('mobile_devices')->insert([
            'user_id' => $user->id,
            'fid_hash' => hash('sha256', $fid),
            'fid_ciphertext' => Crypt::encryptString($fid),
            'platform' => 'android',
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
