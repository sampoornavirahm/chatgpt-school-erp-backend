<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('app_update_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('app', 24);
            $table->string('platform', 24);
            $table->string('latest_version', 32)->default('0.1.0');
            $table->string('minimum_version', 32)->default('0.1.0');
            $table->string('download_url', 2048)->default('');
            $table->text('release_notes')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['app', 'platform']);
        });

        foreach ([
            ['desktop', 'windows'], ['desktop', 'macos'],
            ['parent', 'android'], ['parent', 'ios'],
            ['teacher', 'android'], ['teacher', 'ios'],
        ] as [$app, $platform]) {
            DB::table('app_update_policies')->insert([
                'app' => $app,
                'platform' => $platform,
                'latest_version' => '0.1.0',
                'minimum_version' => '0.1.0',
                'download_url' => '',
                'enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_update_policies');
    }
};
