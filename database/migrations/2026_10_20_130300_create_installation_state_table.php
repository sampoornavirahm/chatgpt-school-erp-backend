<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installation_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('school_id')->nullable();
            $table->timestamp('setup_completed_at')->nullable();
            $table->timestamps();
        });

        DB::table('installation_state')->insert([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_state');
    }
};
