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
        Schema::create('platform_payment_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('merchant_vpa', 255)->nullable();
            $table->string('merchant_name', 120)->default('School ERP');
            $table->timestamps();
        });

        DB::table('platform_payment_settings')->insert([
            'id' => 1,
            'merchant_vpa' => config('school_erp.merchant_upi_vpa'),
            'merchant_name' => config('school_erp.merchant_upi_name', 'School ERP'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_payment_settings');
    }
};
