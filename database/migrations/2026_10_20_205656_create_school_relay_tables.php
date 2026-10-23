<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_bootstrap_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->timestamp('consumed_at')->nullable();
        });
        DB::table('platform_bootstrap_state')->insert(['id' => 1, 'consumed_at' => null]);

        Schema::table('schools', function (Blueprint $table): void {
            $table->string('relay_status', 16)->default('active');
            $table->unsignedBigInteger('storage_quota_bytes')->default(53687091200);
            $table->string('license_plan_key', 40)->default('trial');
            $table->string('license_status', 20)->default('trial');
            $table->unsignedInteger('active_student_limit')->default(100);
            $table->unsignedInteger('active_teacher_limit')->default(1);
            $table->timestamp('license_expires_at')->nullable();
        });

        Schema::create('school_relay_credentials', function (Blueprint $table): void {
            $table->foreignUuid('school_id')->primary()->constrained()->cascadeOnDelete();
            $table->uuid('key_id')->unique();
            $table->text('secret_ciphertext');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('relay_request_nonces', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->string('nonce', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['school_id', 'nonce']);
            $table->index('created_at');
        });

        Schema::create('relay_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->uuid('batch_id');
            $table->char('request_hash', 64);
            $table->json('response_json');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['school_id', 'batch_id']);
        });

        Schema::create('relay_snapshots', function (Blueprint $table): void {
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->uuid('student_public_id');
            $table->unsignedBigInteger('revision');
            $table->char('content_hash', 64);
            $table->json('payload');
            $table->unsignedBigInteger('payload_bytes');
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('projected_at');
            $table->primary(['school_id', 'student_public_id']);
            $table->index(['school_id', 'projected_at']);
        });

        Schema::create('relay_parent_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('student_public_id');
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['school_id', 'user_id', 'student_public_id'], 'relay_parent_link_unique');
            $table->index(['school_id', 'student_public_id']);
        });

        Schema::create('relay_parent_commands', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('student_public_id');
            $table->string('operation', 64);
            $table->json('payload');
            $table->string('status', 16)->default('queued');
            $table->string('result_message', 240)->default('');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unique(['school_id', 'operation_id'], 'relay_parent_command_unique');
            $table->index(['school_id', 'status', 'id']);
            $table->index(['user_id', 'id']);
        });

        Schema::create('relay_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('target_type', 40)->default('school');
            $table->string('target_id', 100)->default('');
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['school_id', 'created_at']);
        });

        Schema::create('relay_webhook_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->string('event_id', 180);
            $table->char('payload_hash', 64);
            $table->string('status', 24);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relay_webhook_receipts');
        Schema::dropIfExists('relay_audit_logs');
        Schema::dropIfExists('relay_parent_commands');
        Schema::dropIfExists('relay_parent_links');
        Schema::dropIfExists('relay_snapshots');
        Schema::dropIfExists('relay_batches');
        Schema::dropIfExists('relay_request_nonces');
        Schema::dropIfExists('school_relay_credentials');
        Schema::dropIfExists('platform_bootstrap_state');

        Schema::table('schools', function (Blueprint $table): void {
            $table->dropColumn([
                'relay_status',
                'storage_quota_bytes',
                'license_plan_key',
                'license_status',
                'active_student_limit',
                'active_teacher_limit',
                'license_expires_at',
            ]);
        });
    }
};
