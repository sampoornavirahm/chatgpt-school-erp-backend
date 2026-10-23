<?php

namespace Tests\Feature;

use App\Models\ActivationCode;
use App\Models\LicensePlan;
use App\Models\School;
use App\Models\User;
use App\Services\GoogleIdentityVerifier;
use App\Services\SchoolLicenseService;
use Database\Seeders\LicensePlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LicensingEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_google_enrollment_waits_for_operator_approval_before_license_is_issued(): void
    {
        $this->seed(LicensePlanSeeder::class);
        $school = School::factory()->create(['name' => 'Greenfield UAT School']);
        User::factory()->for($school)->create([
            'name' => 'School Administrator',
            'email' => 'admin@greenfield.example',
            'password' => 'school-admin-password',
            'role' => 'school_admin',
            'is_active' => true,
        ]);
        $operator = User::factory()->create(['school_id' => null, 'role' => 'platform_admin', 'is_active' => true]);
        $activationCode = app(SchoolLicenseService::class)->createActivationCode(
            $school,
            $operator,
            LicensePlan::query()->where('key', 'professional_school')->firstOrFail(),
            75,
            8,
            null,
            null,
        );
        $localServerId = (string) Str::uuid();

        $this->mock(GoogleIdentityVerifier::class, function ($mock): void {
            $mock->shouldReceive('verify')->once()->with('valid-google-id-token')->andReturn([
                'sub' => 'google-subject-greenfield',
                'email' => 'admin@greenfield.example',
                'email_verified' => true,
            ]);
        });

        $this->postJson('/api/v1/licensing/enroll', [
            'activation_code' => $activationCode,
            'server_id' => $localServerId,
            'google_id_token' => 'valid-google-id-token',
        ])->assertAccepted()
            ->assertJsonPath('status', 'pending_operator_approval')
            ->assertJsonPath('school.id', $school->id)
            ->assertJsonPath('school.name', 'Greenfield UAT School');

        $activation = ActivationCode::query()->where('school_id', $school->id)->sole();
        $this->assertSame($localServerId, $school->fresh()->server_id);
        $this->assertSame('verified', $school->fresh()->google_link_status);
        $this->assertSame('pending_approval', $school->fresh()->activation_status);
        $this->assertSame('admin@greenfield.example', $activation->requested_google_email);
        $this->assertSame($localServerId, $activation->requested_server_id);
        $this->assertNull($activation->approved_at);
        $this->assertDatabaseCount('school_licenses', 0);

        $this->postJson('/api/v1/licensing/activate', [
            'school_id' => $school->id,
            'server_id' => $localServerId,
            'activation_code' => $activationCode,
        ])->assertStatus(409);

        $this->actingAs($operator)->post(route('operator.schools.activation-codes.approve', [$school, $activation]))
            ->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('activation_codes', [
            'id' => $activation->id,
            'approved_by' => $operator->id,
            'consumed_server_id' => $localServerId,
        ]);
        $this->assertSame('active', $school->fresh()->activation_status);
        $this->assertSame('professional_school', $school->fresh()->currentLicense->plan_key);
        $this->assertDatabaseHas('school_relay_credentials', [
            'school_id' => $school->id,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/licensing/enrollment-status', [
            'activation_code' => $activationCode,
            'server_id' => $localServerId,
        ])->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('license.plan', 'professional_school')
            ->assertJsonPath('relay.school_id', $school->id)
            ->assertJsonPath('relay.url', url('/api/v1'));
    }

    public function test_enrollment_rejects_a_google_account_that_is_not_the_school_administrator(): void
    {
        $this->seed(LicensePlanSeeder::class);
        $school = School::factory()->create();
        User::factory()->for($school)->create([
            'email' => 'admin@greenfield.example',
            'password' => 'school-admin-password',
            'role' => 'school_admin',
            'is_active' => true,
        ]);
        $operator = User::factory()->create(['school_id' => null, 'role' => 'platform_admin', 'is_active' => true]);
        $activationCode = app(SchoolLicenseService::class)->createActivationCode(
            $school,
            $operator,
            LicensePlan::query()->where('key', 'starter')->firstOrFail(),
            null,
            null,
            null,
            null,
        );
        $this->mock(GoogleIdentityVerifier::class, function ($mock): void {
            $mock->shouldReceive('verify')->once()->with('valid-google-id-token')->andReturn([
                'sub' => 'another-google-subject',
                'email' => 'someone-else@example.test',
                'email_verified' => true,
            ]);
        });

        $this->postJson('/api/v1/licensing/enroll', [
            'activation_code' => $activationCode,
            'server_id' => (string) Str::uuid(),
            'google_id_token' => 'valid-google-id-token',
        ])->assertUnprocessable()->assertJsonValidationErrors('google_id_token');

        $this->assertNull(ActivationCode::query()->sole()->enrollment_requested_at);
        $this->assertNull($school->fresh()->google_subject);
    }
}
