<?php

namespace Tests\Feature;

use App\Models\ActivationCode;
use App\Models\LicensePlan;
use App\Models\School;
use App\Models\SchoolLicense;
use App\Models\SubscriptionContract;
use App\Models\SubscriptionOrder;
use App\Models\User;
use App\Services\GoogleIdentityVerifier;
use App\Services\SchoolLicenseService;
use Database\Seeders\LicensePlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LicensingAndBillingTest extends TestCase
{
    use RefreshDatabase;

    private string $initialSchoolAdminToken = '';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'school_erp.trial_enabled' => true,
            'school_erp.payment_driver' => 'dummy',
            'school_erp.allow_dummy_payments' => true,
            'school_erp.merchant_upi_vpa' => null,
        ]);
    }

    public function test_plan_catalog_contains_the_supplied_inr_prices_and_billing_terms(): void
    {
        $this->seed(LicensePlanSeeder::class);

        $this->getJson('/api/v1/licensing/plans')->assertOk()
            ->assertJsonPath('currency', 'INR')
            ->assertJsonPath('plans.0.key', 'starter')
            ->assertJsonPath('plans.0.student_limit', 100)
            ->assertJsonPath('plans.0.teacher_limit', 1)
            ->assertJsonPath('plans.2.key', 'professional_school')
            ->assertJsonPath('plans.2.student_price_paise', 15000)
            ->assertJsonPath('plans.2.billing_period', 'annual')
            ->assertJsonPath('plans.3.parent_price_paise', 20000)
            ->assertJsonPath('plans.3.teacher_price_paise', 50000)
            ->assertJsonPath('plans.4.billing_mode', 'installments');
    }

    public function test_operator_can_render_plan_and_school_license_pages(): void
    {
        $this->seed(LicensePlanSeeder::class);
        $this->completeSchoolSetup();
        $school = School::query()->sole();
        $operator = $this->createPlatformOperator();

        $this->actingAs($operator)->get(route('operator.plans.index'))->assertOk()->assertSee('License plans');
        $this->actingAs($operator)->get(route('operator.schools.show', $school))->assertOk()
            ->assertSee('Trial and license')->assertSee('Elite custom agreement')->assertSee('Payment QR');
    }

    public function test_school_can_request_trial_extension_and_only_operator_approval_issues_it(): void
    {
        $this->travelTo(now()->startOfDay());
        $this->completeSchoolSetup();
        $school = School::query()->sole();
        $this->withToken($this->initialSchoolAdminToken)->postJson('/api/v1/licensing/trial-extension-requests', [
            'note' => 'We need time to finish the school setup.',
        ])->assertAccepted()->assertJsonPath('status', 'pending');
        $this->assertDatabaseHas('trial_extension_requests', [
            'school_id' => $school->id,
            'status' => 'pending',
            'extension_days' => 15,
        ]);
        $this->assertSame(now()->addDays(15)->toDateTimeString(), $school->fresh()->trial_expires_at->toDateTimeString());

        $operator = $this->createPlatformOperator();
        $this->actingAs($operator)->post(route('operator.schools.trial-extension', $school))->assertRedirect();
        $this->assertSame(now()->addDays(30)->toDateTimeString(), $school->fresh()->trial_expires_at->toDateTimeString());
        $this->assertDatabaseHas('trial_extension_requests', ['school_id' => $school->id, 'status' => 'approved']);
    }

    public function test_manual_cheque_reconciliation_activates_the_parent_entitlement(): void
    {
        $this->seed(LicensePlanSeeder::class);
        $this->completeSchoolSetup();
        $school = School::query()->sole();
        $parent = User::factory()->for($school)->state([
            'name' => 'Parent', 'email' => 'manual-parent@example.test', 'password' => 'parent-password-long', 'role' => 'parent', 'is_active' => true,
        ])->create();
        $parentToken = $this->login($parent);
        $order = $this->withToken($parentToken)->postJson('/api/v1/billing/orders', [
            'plan_key' => 'professional_distributed',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->json('order');

        $operator = $this->createPlatformOperator();
        $this->actingAs($operator)->post(route('operator.schools.orders.reconcile-payment', [$school, $order['id']]), [
            'method' => 'cheque',
            'reference' => 'CHQ-1042',
            'paid_at' => now()->format('Y-m-d H:i:s'),
            'note' => 'Cheque received at the school office.',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('subscription_orders', ['id' => $order['id'], 'status' => 'paid', 'payment_provider' => 'manual']);
        $this->assertDatabaseHas('payment_reconciliations', ['subscription_order_id' => $order['id'], 'method' => 'cheque', 'reference' => 'CHQ-1042']);
        $this->assertDatabaseHas('user_entitlements', ['school_id' => $school->id, 'user_id' => $parent->id, 'plan_key' => 'professional_distributed', 'status' => 'active']);
    }

    public function test_school_admin_can_store_own_gateway_credentials_encrypted_without_returning_secrets(): void
    {
        $this->completeSchoolSetup();
        $school = School::query()->sole();
        $this->withToken($this->initialSchoolAdminToken)->putJson('/api/v1/school/payment-settings', [
            'provider' => 'razorpay',
            'merchant_vpa' => 'fees@upi',
            'merchant_name' => 'Example School',
            'provider_account_id' => 'acct_school_123',
            'credentials' => ['key_id' => 'rzp_test_abc', 'key_secret' => 'secret-value-never-return'],
            'is_enabled' => true,
        ])->assertOk()
            ->assertJsonPath('settings.provider', 'razorpay')
            ->assertJsonPath('settings.credentials_configured', true)
            ->assertJsonMissing(['key_secret' => 'secret-value-never-return']);

        $stored = DB::table('school_payment_settings')->where('school_id', $school->id)->sole();
        $this->assertStringNotContainsString('secret-value-never-return', $stored->credentials_ciphertext);
        $this->withToken($this->initialSchoolAdminToken)->getJson('/api/v1/school/payment-settings')->assertOk()
            ->assertJsonMissing(['key_id' => 'rzp_test_abc']);
    }

    public function test_school_on_hold_can_sign_in_and_renew_but_cannot_use_parent_services(): void
    {
        $this->completeSchoolSetup();
        $school = School::query()->sole();
        $parent = User::factory()->for($school)->state([
            'name' => 'Parent', 'email' => 'hold-parent@example.test', 'password' => 'parent-password-long', 'role' => 'parent', 'is_active' => true,
        ])->create();
        $operator = $this->createPlatformOperator();
        $this->actingAs($operator)->post(route('operator.schools.service-access', $school), [
            'service_access' => 'on_hold', 'support_note' => 'Offline payment pending.',
        ])->assertRedirect();

        $token = $this->login($parent);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($token)->getJson('/api/v1/licensing/status')->assertOk()->assertJsonPath('service_on_hold', true);
        $this->withToken($token)->getJson('/api/v1/billing/orders')->assertOk();
        $this->withToken($token)->getJson('/api/v1/parent/children')->assertStatus(423)->assertJsonPath('error.code', 'SCHOOL_ON_HOLD');
        $this->assertDatabaseHas('schools', ['id' => $school->id, 'service_on_hold' => true, 'relay_status' => 'paused']);
    }

    public function test_operator_can_enable_archive_access_only_for_a_configured_window(): void
    {
        $this->completeSchoolSetup();
        $school = School::query()->sole();
        $operator = $this->createPlatformOperator();
        $startsAt = today()->toDateString();
        $endsAt = today()->addDays(30)->toDateString();
        $this->actingAs($operator)->post(route('operator.schools.archive-access', $school), [
            'archive_access_enabled' => '1',
            'archive_access_starts_at' => $startsAt,
            'archive_access_ends_at' => $endsAt,
            'archive_free_reads_limit' => 5,
            'archive_query_price' => '75.00',
            'archive_access_note' => 'Offline fixed payment for one month.',
        ])->assertRedirect();

        $this->withToken($this->initialSchoolAdminToken)->getJson('/api/v1/licensing/status')->assertOk()
            ->assertJsonPath('archive_access.enabled', true)
            ->assertJsonPath('archive_access.free_reads_limit', 5)
            ->assertJsonPath('archive_access.query_price_paise', 7500);
    }

    public function test_trial_starts_at_15_days_and_operator_can_extend_it_to_30_days_once(): void
    {
        $this->travelTo(now()->startOfDay());
        $this->completeSchoolSetup();
        $school = School::query()->sole();
        $this->assertSame(now()->addDays(15)->toDateTimeString(), $school->trial_expires_at->toDateTimeString());

        $operator = $this->createPlatformOperator();
        $this->actingAs($operator)->post(route('operator.schools.trial-extension', $school))->assertRedirect();
        $school->refresh();
        $this->assertSame(now()->addDays(30)->toDateTimeString(), $school->trial_expires_at->toDateTimeString());
        $this->assertNotNull($school->trial_extended_at);

        $this->actingAs($operator)->post(route('operator.schools.trial-extension', $school))->assertSessionHasErrors('trial');
        $this->assertSame(now()->addDays(30)->toDateTimeString(), $school->fresh()->trial_expires_at->toDateTimeString());
    }

    public function test_legacy_trial_school_backfill_uses_its_original_start_date(): void
    {
        $startedAt = now()->subDays(5);
        $school = School::factory()->create([
            'license_plan_key' => 'trial',
            'license_status' => 'trial',
            'trial_started_at' => $startedAt,
            'trial_expires_at' => null,
            'current_license_id' => null,
        ]);

        $this->artisan('license:backfill-trials')->expectsOutput('Issued 1 signed Starter trial license(s).')->assertExitCode(0);

        $license = SchoolLicense::query()->where('school_id', $school->id)->sole();
        $this->assertSame($startedAt->copy()->addDays(15)->toDateTimeString(), $school->fresh()->trial_expires_at->toDateTimeString());
        $this->assertSame($license->id, $school->fresh()->current_license_id);
        $this->assertSame('starter', $license->claims['plan']);
    }

    public function test_starter_plan_enforces_its_one_teacher_limit(): void
    {
        $this->completeSchoolSetup();
        $school = School::query()->sole();
        $admin = $school->users()->where('role', 'school_admin')->firstOrFail();
        $token = $admin->createToken('seat-limit-test')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/admin/users', [
            'name' => 'First Teacher', 'email' => 'teacher.one@example.test', 'password' => 'teacher-password-long', 'role' => 'teacher',
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/admin/users', [
            'name' => 'Second Teacher', 'email' => 'teacher.two@example.test', 'password' => 'teacher-password-long', 'role' => 'teacher',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertDatabaseCount('users', 2);
    }

    public function test_basic_adware_inherits_starter_seat_limits_by_default(): void
    {
        $this->seed(LicensePlanSeeder::class);
        $this->completeSchoolSetup();
        $school = School::query()->sole();
        $plan = LicensePlan::query()->where('key', 'basic_adware')->firstOrFail();
        $license = app(SchoolLicenseService::class)->issue($school, $plan, null, null, null, null);

        $this->withToken($this->initialSchoolAdminToken)->postJson('/api/v1/admin/users', [
            'name' => 'First Teacher', 'email' => 'adware.teacher.one@example.test', 'password' => 'teacher-password-long', 'role' => 'teacher',
        ])->assertCreated();
        $this->withToken($this->initialSchoolAdminToken)->postJson('/api/v1/admin/users', [
            'name' => 'Second Teacher', 'email' => 'adware.teacher.two@example.test', 'password' => 'teacher-password-long', 'role' => 'teacher',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->assertSame(['students' => 100, 'teachers' => 1], $license->claims['limits']);
    }

    public function test_activation_issues_a_signed_license_and_same_server_retry_is_idempotent(): void
    {
        $this->seed(LicensePlanSeeder::class);
        $this->completeSchoolSetup();
        $school = School::query()->sole();
        $operator = $this->createPlatformOperator();

        $this->actingAs($operator)->post(route('operator.schools.activation-codes.store', $school), [
            'plan_key' => 'professional_school',
            'student_seats' => 42,
        ])->assertRedirect()->assertSessionHas('activation_code');
        $code = session('activation_code');
        $serverId = (string) Str::uuid();
        $this->mock(GoogleIdentityVerifier::class, function ($mock): void {
            $mock->shouldReceive('verify')->once()->with('school-google-id-token')->andReturn([
                'sub' => 'school-admin-google-subject',
                'email' => 'example-school@example.test',
                'email_verified' => true,
            ]);
        });
        $this->postJson('/api/v1/licensing/enroll', [
            'activation_code' => $code,
            'server_id' => $serverId,
            'google_id_token' => 'school-google-id-token',
        ])->assertAccepted()->assertJsonPath('status', 'pending_operator_approval');
        $this->assertDatabaseHas('schools', ['id' => $school->id, 'activation_status' => 'pending_approval', 'server_id' => $serverId]);
        $this->assertDatabaseHas('activation_codes', ['school_id' => $school->id, 'requested_server_id' => $serverId]);

        $activation = $this->postJson('/api/v1/licensing/activate', [
            'school_id' => $school->id,
            'server_id' => $serverId,
            'activation_code' => $code,
        ])->assertStatus(409);
        $activationCode = ActivationCode::query()->where('school_id', $school->id)->sole();
        $this->actingAs($operator)->post(route('operator.schools.activation-codes.approve', [$school, $activationCode]))
            ->assertRedirect()->assertSessionHas('status');

        $activation = $this->postJson('/api/v1/licensing/activate', [
            'school_id' => $school->id,
            'server_id' => $serverId,
            'activation_code' => $code,
        ]);
        $activation->assertOk()->assertJsonPath('license.plan', 'professional_school')
            ->assertJsonPath('license.limits.students', 42)
            ->assertJsonPath('license.currency', 'INR');
        $token = $activation->json('license.token');

        $this->postJson('/api/v1/licensing/activate', [
            'school_id' => $school->id,
            'server_id' => $serverId,
            'activation_code' => $code,
        ])->assertOk()->assertJsonPath('idempotent', true)->assertJsonPath('license.token', $token);

        $verification = $this->postJson('/api/v1/licensing/verify', ['token' => $token]);
        $verification->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('claims.limits.students', 42)
            ->assertJsonPath('claims.offline_grace_days', 7);
        $this->postJson('/api/v1/licensing/verify', ['token' => $token.'tampered'])->assertUnprocessable()
            ->assertJsonPath('valid', false);
        $this->assertDatabaseHas('school_licenses', ['school_id' => $school->id, 'plan_key' => 'professional_school', 'status' => 'active']);
    }

    public function test_parent_can_only_buy_their_own_distributed_entitlement_at_the_seeded_price(): void
    {
        $this->seed(LicensePlanSeeder::class);
        $this->completeSchoolSetup();
        $school = School::query()->sole();
        $parent = User::factory()->for($school)->state([
            'name' => 'Parent', 'email' => 'parent@example.test', 'password' => 'parent-password-long', 'role' => 'parent', 'is_active' => true,
        ])->create();
        $otherUser = User::factory()->for($school)->state([
            'name' => 'Other Parent', 'email' => 'other-parent@example.test', 'password' => 'other-password-long', 'role' => 'parent', 'is_active' => true,
        ])->create();

        config(['school_erp.merchant_upi_vpa' => 'school@upi']);
        $parentToken = $this->login($parent);
        $order = $this->withToken($parentToken)->postJson('/api/v1/billing/orders', [
            'plan_key' => 'professional_distributed',
            'idempotency_key' => (string) Str::uuid(),
            'target_user_id' => $otherUser->id,
            'amount_paise' => 1,
        ])->assertCreated()->assertJsonPath('order.amount_paise', 20000)
            ->assertJsonPath('order.user_id', $parent->id)
            ->assertJsonPath('order.currency', 'INR')
            ->assertJsonPath('payment_mode', 'merchant_upi_qr')
            ->assertJsonPath('qr_ready', true);
        parse_str((string) parse_url($order->json('order.payment_uri'), PHP_URL_QUERY), $upiDetails);
        $this->assertSame('school@upi', $upiDetails['pa']);
        $this->assertSame('200.00', $upiDetails['am']);
        $orderId = $order->json('order.id');

        $this->withToken($parentToken)->postJson("/api/v1/billing/orders/{$orderId}/simulate-payment", [])->assertOk()
            ->assertJsonPath('order.status', 'paid');
        $this->withToken($parentToken)->postJson("/api/v1/billing/orders/{$orderId}/simulate-payment", [])->assertOk()
            ->assertJsonPath('duplicate', true);
        $this->assertDatabaseHas('user_entitlements', [
            'school_id' => $school->id,
            'user_id' => $parent->id,
            'plan_key' => 'professional_distributed',
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('user_entitlements', ['user_id' => $otherUser->id]);
    }

    public function test_school_professional_order_uses_active_student_count_and_cannot_be_paid_by_another_tenant(): void
    {
        $this->seed(LicensePlanSeeder::class);
        $this->completeSchoolSetup();
        $school = School::query()->sole();
        $studentIds = [(string) Str::uuid(), (string) Str::uuid()];
        foreach ($studentIds as $index => $studentId) {
            DB::table('relay_snapshots')->insert([
                'school_id' => $school->id,
                'student_public_id' => $studentId,
                'revision' => 1,
                'content_hash' => hash('sha256', (string) $index),
                'payload' => json_encode(['student' => ['fullName' => 'Student '.($index + 1)]], JSON_THROW_ON_ERROR),
                'payload_bytes' => 32,
                'is_deleted' => $index === 1,
                'projected_at' => now(),
            ]);
        }

        $order = $this->withToken($this->initialSchoolAdminToken)->postJson('/api/v1/billing/orders', [
            'plan_key' => 'professional_school',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('order.quantity', 1)
            ->assertJsonPath('order.amount_paise', 15000);
        $orderId = $order->json('order.id');

        $otherSchool = School::factory()->create();
        $otherSchoolAdmin = User::factory()->for($otherSchool)->create(['role' => 'school_admin', 'is_active' => true]);
        $otherAdminToken = $otherSchoolAdmin->createToken('test')->plainTextToken;
        $this->withToken($otherAdminToken)->postJson("/api/v1/billing/orders/{$orderId}/simulate-payment", [])->assertNotFound();
        $this->assertDatabaseHas('subscription_orders', ['id' => $orderId, 'status' => 'pending']);
    }

    public function test_elite_license_is_issued_only_after_every_custom_installment_is_paid(): void
    {
        $this->completeSchoolSetup();
        $school = School::query()->sole();
        $operator = $this->createPlatformOperator();
        $firstDueDate = now()->addDay()->toDateString();
        $secondDueDate = now()->addDays(2)->toDateString();

        $this->actingAs($operator)->post(route('operator.schools.elite-contracts.store', $school), [
            'services_summary' => 'Custom reporting and onboarding',
            'installment_amounts' => "1200.00\n800.00",
            'installment_due_dates' => "{$firstDueDate}, {$secondDueDate}",
            'student_seats' => 180,
            'teacher_seats' => 12,
            'feature_flags' => 'reports.custom onboarding.priority',
            'license_expires_at' => now()->addYear()->toDateString(),
        ])->assertRedirect();

        $contract = SubscriptionContract::query()->sole();
        $orders = SubscriptionOrder::query()->where('contract_id', $contract->id)->orderBy('installment_number')->get();
        $this->assertSame(200000, $contract->total_amount_paise);
        $this->assertCount(2, $orders);

        $this->withToken($this->initialSchoolAdminToken)->postJson("/api/v1/billing/orders/{$orders[0]->id}/simulate-payment", [])->assertOk();
        $this->assertSame('pending', $contract->fresh()->status);
        $this->assertSame('trial', $school->fresh()->license_status);

        $this->withToken($this->initialSchoolAdminToken)->postJson("/api/v1/billing/orders/{$orders[1]->id}/simulate-payment", [])->assertOk();
        $contract->refresh();
        $this->assertSame('active', $contract->status);
        $this->assertSame('elite', $school->fresh()->license_plan_key);
        $license = SchoolLicense::query()->whereKey($contract->school_license_id)->sole();
        $this->assertSame('reports.custom', $license->claims['feature_flags'][0]);
        $this->assertSame('basic_adware', $license->claims['base_plan']);
        $this->assertSame('Custom reporting and onboarding', $license->claims['custom_services']);
        $this->assertSame(180, $license->claims['limits']['students']);
    }

    public function test_elite_license_cannot_be_granted_by_a_direct_activation_code(): void
    {
        $this->completeSchoolSetup();
        $school = School::query()->sole();
        $operator = $this->createPlatformOperator();

        $this->actingAs($operator)->post(route('operator.schools.activation-codes.store', $school), [
            'plan_key' => 'elite',
            'license_expires_at' => now()->addYear()->toDateString(),
        ])->assertSessionHasErrors('plan_key');

        $this->assertDatabaseCount('activation_codes', 0);
    }

    public function test_elite_installments_must_have_a_positive_amount(): void
    {
        $this->completeSchoolSetup();
        $school = School::query()->sole();
        $operator = $this->createPlatformOperator();

        $this->actingAs($operator)->post(route('operator.schools.elite-contracts.store', $school), [
            'services_summary' => 'Custom reporting',
            'installment_amounts' => "0.00\n100.00",
            'installment_due_dates' => now()->addDay()->toDateString()."\n".now()->addDays(2)->toDateString(),
            'license_expires_at' => now()->addYear()->toDateString(),
        ])->assertSessionHasErrors('installment_amounts');

        $this->assertDatabaseCount('subscription_contracts', 0);
    }

    public function test_expired_trial_is_on_hold_but_sign_in_and_renewal_status_remain_available(): void
    {
        $this->completeSchoolSetup();
        $school = School::query()->sole();
        $admin = $school->users()->where('role', 'school_admin')->firstOrFail();
        $license = $school->currentLicense;
        $this->travelTo($school->trial_expires_at->copy()->addDays(8));

        $this->postJson('/api/v1/licensing/verify', ['token' => $license->signed_token])->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('state', 'expired');
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'a-long-test-password',
            'role' => 'school_admin',
        ])->assertOk()->json('token');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($token)->getJson('/api/v1/licensing/status')->assertOk()->assertJsonPath('service_on_hold', true);
        $this->withToken($token)->postJson('/api/v1/admin/users', [
            'name' => 'Blocked Teacher', 'email' => 'blocked.teacher@example.test', 'password' => 'teacher-password-long', 'role' => 'teacher',
        ])->assertStatus(423)->assertJsonPath('error.code', 'SCHOOL_ON_HOLD');
        $this->withToken($token)->postJson('/api/v1/billing/orders', [
            'plan_key' => 'professional_school', 'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('plan_key');

        $this->assertDatabaseHas('schools', ['id' => $school->id, 'name' => 'Example School']);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'email' => $admin->email]);
    }

    public function test_revoked_license_keeps_safe_reads_available_and_blocks_changes(): void
    {
        $this->completeSchoolSetup();
        $school = School::query()->sole();
        $admin = $school->users()->where('role', 'school_admin')->firstOrFail();
        $token = $this->initialSchoolAdminToken;
        $operator = $this->createPlatformOperator();

        $this->actingAs($operator)->post(route('operator.schools.licenses.revoke', [$school, $school->currentLicense]))
            ->assertRedirect();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($token)->postJson('/api/v1/admin/users', [
            'name' => 'Blocked Teacher', 'email' => 'revoked.teacher@example.test', 'password' => 'teacher-password-long', 'role' => 'teacher',
        ])->assertForbidden()->assertJsonPath('error.code', 'LICENSE_REVOKED_READ_ONLY');

        $this->assertDatabaseHas('school_licenses', ['id' => $school->current_license_id, 'status' => 'revoked']);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'email' => $admin->email]);
    }

    private function completeSchoolSetup(string $schoolName = 'Example School'): void
    {
        $response = $this->postJson('/api/v1/setup/complete', [
            'school_name' => $schoolName,
            'admin_name' => 'School Admin',
            'email' => Str::slug($schoolName).'@example.test',
            'password' => 'a-long-test-password',
        ])->assertCreated();
        $this->initialSchoolAdminToken = $response->json('token');
    }

    private function login(User $user): string
    {
        return $user->createToken('test', [$user->role])->plainTextToken;
    }

    private function createPlatformOperator(): User
    {
        return User::query()->create([
            'school_id' => null,
            'name' => 'Platform Operator',
            'email' => 'platform@example.test',
            'password' => 'platform-admin-password-long',
            'role' => 'platform_admin',
            'is_active' => true,
        ]);
    }
}
