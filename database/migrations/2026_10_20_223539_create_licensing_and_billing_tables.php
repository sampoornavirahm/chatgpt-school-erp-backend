<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table): void {
            $table->timestamp('trial_expires_at')->nullable();
            $table->timestamp('trial_extended_at')->nullable();
            $table->index(['license_plan_key', 'license_status']);
        });

        Schema::create('license_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 100);
            $table->string('description', 500)->default('');
            $table->string('currency', 3)->default('INR');
            $table->string('billing_period', 20);
            $table->string('billing_mode', 24);
            $table->unsignedBigInteger('school_price_paise')->nullable();
            $table->unsignedBigInteger('student_price_paise')->nullable();
            $table->unsignedBigInteger('parent_price_paise')->nullable();
            $table->unsignedBigInteger('teacher_price_paise')->nullable();
            $table->unsignedInteger('student_limit')->nullable();
            $table->unsignedInteger('teacher_limit')->nullable();
            $table->json('feature_flags');
            $table->boolean('ad_supported')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('school_licenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->string('plan_key', 40);
            $table->string('status', 20);
            $table->json('claims');
            $table->longText('signed_token');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('issued_at');
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('grace_until')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['school_id', 'status', 'issued_at']);
        });

        Schema::table('schools', function (Blueprint $table): void {
            $table->foreignUuid('current_license_id')->nullable()->constrained('school_licenses')->nullOnDelete();
        });

        Schema::create('activation_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->string('plan_key', 40);
            $table->char('code_hash', 64)->unique();
            $table->unsignedInteger('student_seats')->nullable();
            $table->unsignedInteger('teacher_seats')->nullable();
            $table->json('feature_flags')->nullable();
            $table->timestamp('code_expires_at')->nullable();
            $table->timestamp('license_expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->uuid('consumed_server_id')->nullable();
            $table->foreignUuid('school_license_id')->nullable()->constrained('school_licenses')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['school_id', 'consumed_at']);
        });

        Schema::create('subscription_contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->string('plan_key', 40)->default('elite');
            $table->unsignedBigInteger('total_amount_paise');
            $table->unsignedSmallInteger('installment_count');
            $table->string('services_summary', 1000);
            $table->json('feature_flags');
            $table->unsignedInteger('student_seats')->nullable();
            $table->unsignedInteger('teacher_seats')->nullable();
            $table->timestamp('license_expires_at');
            $table->string('status', 20)->default('pending');
            $table->foreignUuid('school_license_id')->nullable()->constrained('school_licenses')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['school_id', 'status']);
        });

        Schema::create('subscription_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contract_id')->nullable()->constrained('subscription_contracts')->cascadeOnDelete();
            $table->string('plan_key', 40);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('amount_paise');
            $table->string('currency', 3)->default('INR');
            $table->string('status', 20)->default('pending');
            $table->string('payment_provider', 32);
            $table->string('provider_order_id', 128)->nullable();
            $table->char('idempotency_key', 36)->unique();
            $table->text('payment_uri')->nullable();
            $table->string('contract_reference', 80)->nullable();
            $table->unsignedSmallInteger('installment_number')->nullable();
            $table->unsignedSmallInteger('installment_count')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status', 'created_at']);
            $table->index(['user_id', 'status', 'created_at']);
            $table->unique(['contract_id', 'installment_number']);
        });

        Schema::create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('subscription_order_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('event_id', 180);
            $table->char('payload_hash', 64);
            $table->string('status', 24);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->unique(['provider', 'event_id']);
            $table->index(['subscription_order_id', 'status']);
        });

        Schema::create('user_entitlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('plan_key', 40);
            $table->string('status', 20)->default('active');
            $table->json('feature_flags');
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'user_id', 'plan_key']);
            $table->index(['school_id', 'status', 'expires_at']);
        });

        $now = now();
        DB::table('license_plans')->insert([
            ['key' => 'starter', 'name' => 'Starter', 'description' => 'Free tier for a small school.', 'currency' => 'INR', 'billing_period' => 'free', 'billing_mode' => 'free', 'school_price_paise' => 0, 'student_price_paise' => null, 'parent_price_paise' => null, 'teacher_price_paise' => null, 'student_limit' => 100, 'teacher_limit' => 1, 'feature_flags' => json_encode([], JSON_THROW_ON_ERROR), 'ad_supported' => false, 'is_enabled' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'basic_adware', 'name' => 'Basic Adware', 'description' => 'Free access supported by first-party school-approved sponsor placements.', 'currency' => 'INR', 'billing_period' => 'free', 'billing_mode' => 'ad_supported', 'school_price_paise' => 0, 'student_price_paise' => null, 'parent_price_paise' => null, 'teacher_price_paise' => null, 'student_limit' => 100, 'teacher_limit' => 1, 'feature_flags' => json_encode([], JSON_THROW_ON_ERROR), 'ad_supported' => true, 'is_enabled' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'professional_school', 'name' => 'Professional', 'description' => 'School-paid annual plan billed per active student.', 'currency' => 'INR', 'billing_period' => 'annual', 'billing_mode' => 'upfront', 'school_price_paise' => null, 'student_price_paise' => 15000, 'parent_price_paise' => null, 'teacher_price_paise' => null, 'student_limit' => null, 'teacher_limit' => null, 'feature_flags' => json_encode([], JSON_THROW_ON_ERROR), 'ad_supported' => false, 'is_enabled' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'professional_distributed', 'name' => 'Professional Distributed', 'description' => 'Individual annual parent or teacher subscriptions.', 'currency' => 'INR', 'billing_period' => 'annual', 'billing_mode' => 'upfront', 'school_price_paise' => null, 'student_price_paise' => null, 'parent_price_paise' => 20000, 'teacher_price_paise' => 50000, 'student_limit' => null, 'teacher_limit' => null, 'feature_flags' => json_encode([], JSON_THROW_ON_ERROR), 'ad_supported' => false, 'is_enabled' => true, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'elite', 'name' => 'Elite', 'description' => 'Custom school agreement with configurable services and installments.', 'currency' => 'INR', 'billing_period' => 'custom', 'billing_mode' => 'installments', 'school_price_paise' => null, 'student_price_paise' => null, 'parent_price_paise' => null, 'teacher_price_paise' => null, 'student_limit' => null, 'teacher_limit' => null, 'feature_flags' => json_encode([], JSON_THROW_ON_ERROR), 'ad_supported' => false, 'is_enabled' => true, 'sort_order' => 5, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_entitlements');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('subscription_orders');
        Schema::dropIfExists('subscription_contracts');
        Schema::dropIfExists('activation_codes');
        Schema::table('schools', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_license_id');
        });
        Schema::dropIfExists('school_licenses');
        Schema::dropIfExists('license_plans');

        Schema::table('schools', function (Blueprint $table): void {
            $table->dropIndex(['license_plan_key', 'license_status']);
            $table->dropColumn(['trial_expires_at', 'trial_extended_at']);
        });
    }
};
