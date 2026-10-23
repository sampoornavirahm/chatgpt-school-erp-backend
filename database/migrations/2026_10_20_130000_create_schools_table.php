<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->uuid('server_id')->unique();
            $table->string('google_subject', 128)->nullable();
            $table->string('google_email', 255)->nullable();
            $table->string('google_link_status', 32)->default('pending');
            $table->string('activation_status', 32)->default('pending');
            $table->string('activation_id', 128)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('trial_started_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
