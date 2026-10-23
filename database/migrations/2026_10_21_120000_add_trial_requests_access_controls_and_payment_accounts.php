<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table): void {
            $table->timestamp('trial_extension_requested_at')->nullable();
            $table->boolean('service_on_hold')->default(false);
            $table->timestamp('service_on_hold_at')->nullable();
            $table->string('hold_previous_relay_status', 16)->nullable();
            $table->boolean('archive_access_enabled')->default(false);
            $table->timestamp('archive_access_starts_at')->nullable();
            $table->timestamp('archive_access_ends_at')->nullable();
            $table->unsignedInteger('archive_free_reads_limit')->default(0);
            $table->unsignedBigInteger('archive_query_price_paise')->nullable();
            $table->string('archive_access_note', 500)->nullable();
        });

        Schema::create('trial_extension_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('extension_days')->default(15);
            $table->string('school_note', 500)->nullable();
            $table->string('operator_note', 500)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status', 'requested_at']);
        });

        Schema::create('payment_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('subscription_order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('method', 24);
            $table->string('reference', 120)->nullable();
            $table->unsignedBigInteger('amount_paise');
            $table->timestamp('paid_at');
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('school_payment_settings', function (Blueprint $table): void {
            $table->foreignUuid('school_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('none');
            $table->string('merchant_vpa', 255)->nullable();
            $table->string('merchant_name', 120)->nullable();
            $table->string('provider_account_id', 160)->nullable();
            $table->longText('credentials_ciphertext')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_payment_settings');
        Schema::dropIfExists('payment_reconciliations');
        Schema::dropIfExists('trial_extension_requests');
        Schema::table('schools', function (Blueprint $table): void {
            $table->dropColumn([
                'trial_extension_requested_at', 'service_on_hold', 'service_on_hold_at',
                'hold_previous_relay_status', 'archive_access_enabled', 'archive_access_starts_at',
                'archive_access_ends_at', 'archive_free_reads_limit', 'archive_query_price_paise',
                'archive_access_note',
            ]);
        });
    }
};
