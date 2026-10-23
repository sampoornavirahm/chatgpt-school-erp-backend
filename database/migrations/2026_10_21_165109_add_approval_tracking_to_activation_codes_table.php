<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activation_codes', function (Blueprint $table): void {
            $table->uuid('requested_server_id')->nullable()->after('code_expires_at');
            $table->string('requested_google_subject', 128)->nullable()->after('requested_server_id');
            $table->string('requested_google_email', 255)->nullable()->after('requested_google_subject');
            $table->timestamp('enrollment_requested_at')->nullable()->after('requested_google_email');
            $table->timestamp('approved_at')->nullable()->after('enrollment_requested_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activation_codes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn([
                'requested_server_id',
                'requested_google_subject',
                'requested_google_email',
                'enrollment_requested_at',
                'approved_at',
            ]);
        });
    }
};
