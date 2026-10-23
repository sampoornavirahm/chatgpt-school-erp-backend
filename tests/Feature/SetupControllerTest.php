<?php

namespace Tests\Feature;

use App\Models\ActivationCode;
use App\Models\School;
use App\Models\User;
use App\Services\GoogleIdentityVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'school_erp.trial_enabled' => true,
            'school_erp.allow_dummy_activations' => true,
        ]);
    }

    public function test_trial_setup_marks_google_and_activation_checks_as_bypassed(): void
    {
        $this->getJson('/api/v1/setup/status')
            ->assertOk()
            ->assertJsonPath('configured', false)
            ->assertJsonPath('trial_enabled', true)
            ->assertJsonPath('google_account_required', false)
            ->assertJsonPath('server_activation_required', false);

        $this->postJson('/api/v1/setup/complete', [
            'school_name' => 'Example School',
            'admin_name' => 'School Admin',
            'email' => 'admin@example.test',
            'password' => 'a-long-test-password',
        ])->assertCreated()
            ->assertJsonPath('google_link_status', 'bypassed_trial')
            ->assertJsonPath('activation_status', 'bypassed_trial')
            ->assertJsonPath('trial_enabled', true);

        $school = School::query()->sole();
        $this->assertNull($school->google_email);
        $this->assertNull($school->activation_id);

        $this->getJson('/api/v1/setup/status')
            ->assertJsonPath('configured', true)
            ->assertJsonPath('access_ready', true);
    }

    public function test_returns_409_when_school_setup_is_already_completed(): void
    {
        $this->postJson('/api/v1/setup/complete', [
            'school_name' => 'Example School',
            'admin_name' => 'School Admin',
            'email' => 'admin@example.test',
            'password' => 'a-long-test-password',
        ])->assertCreated();

        $this->postJson('/api/v1/setup/complete', [
            'school_name' => 'Another School',
            'admin_name' => 'Another Admin',
            'email' => 'other@example.test',
            'password' => 'another-long-password',
        ])->assertConflict()->assertJsonPath('error.code', 'SETUP_ALREADY_COMPLETED');

        $this->assertDatabaseCount('schools', 1);
    }

    public function test_returns_422_when_nontrial_setup_lacks_google_identity_and_activation(): void
    {
        config([
            'school_erp.trial_enabled' => false,
            'school_erp.google_client_id' => null,
            'school_erp.activation_url' => null,
            'school_erp.activation_shared_secret' => null,
        ]);

        $this->getJson('/api/v1/setup/status')
            ->assertJsonPath('google_account_required', true)
            ->assertJsonPath('server_activation_required', true);

        $this->postJson('/api/v1/setup/complete', [
            'school_name' => 'Example School',
            'admin_name' => 'School Admin',
            'email' => 'admin@example.test',
            'password' => 'a-long-test-password',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.setup.0', 'Attach the school administrator Google account and enter the server activation code.');

        $this->assertDatabaseCount('schools', 0);
    }

    public function test_returns_422_when_verified_google_email_does_not_match_setup_email(): void
    {
        config([
            'school_erp.trial_enabled' => false,
            'school_erp.google_client_id' => 'test-client-id',
            'school_erp.allow_dummy_activations' => true,
        ]);
        $this->mock(GoogleIdentityVerifier::class, function ($mock): void {
            $mock->shouldReceive('verify')->once()->with('synthetic-id-token')->andReturn([
                'sub' => 'synthetic-google-subject',
                'email' => 'different@example.test',
                'email_verified' => true,
            ]);
        });

        $this->postJson('/api/v1/setup/complete', [
            'school_name' => 'Example School',
            'admin_name' => 'School Admin',
            'email' => 'admin@example.test',
            'password' => 'a-long-test-password',
            'google_id_token' => 'synthetic-id-token',
            'activation_code' => 'synthetic-activation-code',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.setup.0', 'Google account verification or server activation failed. Check the setup details and try again.');

        $this->assertDatabaseCount('schools', 0);
    }

    public function test_dummy_activation_provider_creates_a_signed_starter_license_for_nontrial_setup(): void
    {
        config([
            'school_erp.trial_enabled' => false,
            'school_erp.google_client_id' => 'test-client-id',
            'school_erp.allow_dummy_activations' => true,
        ]);
        $this->mock(GoogleIdentityVerifier::class, function ($mock): void {
            $mock->shouldReceive('verify')->once()->with('synthetic-id-token')->andReturn([
                'sub' => 'synthetic-google-subject',
                'email' => 'admin@example.test',
                'email_verified' => true,
            ]);
        });

        $this->postJson('/api/v1/setup/complete', [
            'school_name' => 'Example School',
            'admin_name' => 'School Admin',
            'email' => 'admin@example.test',
            'password' => 'a-long-test-password',
            'google_id_token' => 'synthetic-id-token',
            'activation_code' => 'synthetic-activation-code',
        ])->assertCreated()
            ->assertJsonPath('google_link_status', 'verified')
            ->assertJsonPath('activation_status', 'active')
            ->assertJsonPath('trial_enabled', false)
            ->assertJsonPath('license_status', 'active')
            ->assertJsonPath('user.role', 'school_admin');

        $school = School::query()->sole();
        $this->assertDatabaseHas('schools', [
            'id' => $school->id,
            'google_subject' => 'synthetic-google-subject',
            'google_email' => 'admin@example.test',
            'activation_status' => 'active',
        ]);
        $this->assertStringStartsWith('dummy-', (string) $school->activation_id);
        $this->assertSame('starter', $school->currentLicense->plan_key);
        $this->assertSame(100, $school->currentLicense->claims['limits']['students']);
    }

    public function test_trial_installation_can_be_activated_later_before_trial_is_disabled(): void
    {
        $this->postJson('/api/v1/setup/complete', [
            'school_name' => 'Example School',
            'admin_name' => 'School Admin',
            'email' => 'admin@example.test',
            'password' => 'a-long-test-password',
        ])->assertCreated();

        $school = School::query()->sole();
        $operator = User::factory()->create(['school_id' => null, 'role' => 'platform_admin', 'is_active' => true]);
        $this->actingAs($operator)->post(route('operator.schools.activation-codes.store', $school), ['plan_key' => 'starter'])
            ->assertRedirect()->assertSessionHas('activation_code');
        $activationCode = session('activation_code');

        config(['school_erp.google_client_id' => 'test-client-id']);
        $this->mock(GoogleIdentityVerifier::class, function ($mock): void {
            $mock->shouldReceive('verify')->once()->with('synthetic-id-token')->andReturn([
                'sub' => 'synthetic-google-subject',
                'email' => 'admin@example.test',
                'email_verified' => true,
            ]);
        });

        $this->postJson('/api/v1/setup/activate', [
            'google_id_token' => 'synthetic-id-token',
            'activation_code' => $activationCode,
        ])->assertAccepted()
            ->assertJsonPath('access_ready', false)
            ->assertJsonPath('enrollment_status', 'pending_operator_approval');

        $this->actingAs($operator)->post(route('operator.schools.activation-codes.approve', [$school, ActivationCode::query()->sole()]))
            ->assertRedirect()->assertSessionHas('status');

        config(['school_erp.trial_enabled' => false]);
        $this->getJson('/api/v1/setup/status')
            ->assertJsonPath('trial_enabled', false)
            ->assertJsonPath('access_ready', true)
            ->assertJsonPath('google_link_status', 'verified')
            ->assertJsonPath('activation_status', 'active');
    }
}
