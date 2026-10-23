<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MobileDeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school_erp.trial_enabled' => true]);
    }

    public function test_fcm_installation_id_is_hashed_for_lookup_and_encrypted_at_rest(): void
    {
        $school = $this->createTrialSchool();
        $teacher = User::factory()->for($school)->state([
            'name' => 'Sample Teacher',
            'email' => 'teacher@example.test',
            'password' => 'teacher-password-long',
            'role' => 'teacher',
            'is_active' => true,
        ])->create();
        $token = $teacher->createToken('test', ['teacher'])->plainTextToken;
        $fid = 'synthetic-firebase-installation-id-123456';

        $this->withToken($token)->postJson('/api/v1/devices/fcm-token', [
            'fid' => $fid,
            'platform' => 'android',
        ])->assertOk()->assertJsonPath('registered', true);

        $device = DB::table('mobile_devices')->sole();
        $this->assertSame(hash('sha256', $fid), $device->fid_hash);
        $this->assertNotSame($fid, $device->fid_ciphertext);
        $this->assertSame($fid, Crypt::decryptString($device->fid_ciphertext));

        $this->withToken($token)->deleteJson('/api/v1/devices/fcm-token', [
            'fid' => $fid,
        ])->assertOk()->assertJsonPath('revoked', true);

        $this->assertNotNull(DB::table('mobile_devices')->sole()->revoked_at);
    }

    public function test_returns_401_when_fcm_registration_has_no_session(): void
    {
        $this->postJson('/api/v1/devices/fcm-token', [
            'fid' => 'synthetic-firebase-installation-id-123456',
            'platform' => 'ios',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('mobile_devices', 0);
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
}
