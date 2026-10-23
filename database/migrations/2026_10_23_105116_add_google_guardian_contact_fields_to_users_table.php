<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 24)->nullable()->after('email');
            $table->string('google_subject', 128)->nullable()->after('phone');
            $table->unsignedBigInteger('relay_user_id')->nullable()->after('google_subject');
            $table->unique(['school_id', 'relay_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['school_id', 'relay_user_id']);
            $table->dropColumn(['phone', 'google_subject', 'relay_user_id']);
        });
    }
};
