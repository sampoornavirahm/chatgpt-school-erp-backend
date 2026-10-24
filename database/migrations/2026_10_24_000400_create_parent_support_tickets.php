<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignUuid('school_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('operation_id')->unique('parent_support_operation_id_unique');
            $table->string('requester_name', 120)->nullable();
            $table->string('requester_email')->nullable();
            $table->string('requester_phone', 24)->nullable();
            $table->string('requester_school', 160)->nullable();
            $table->uuid('student_public_id')->nullable();
            $table->string('category', 32);
            $table->string('subject', 160);
            $table->text('message');
            $table->string('status', 24)->default('open');
            $table->string('escalation_target', 24)->default('platform');
            $table->text('response_note')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at'], 'parent_support_status_created_index');
            $table->index(['user_id', 'created_at'], 'parent_support_user_created_index');
        });

        Schema::create('parent_support_ticket_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('parent_support_tickets')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 32);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->string('escalation_target', 24)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ticket_id', 'id'], 'parent_support_events_ticket_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_support_ticket_events');
        Schema::dropIfExists('parent_support_tickets');
    }
};
