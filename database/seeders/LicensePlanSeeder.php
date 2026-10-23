<?php

namespace Database\Seeders;

use App\Models\LicensePlan;
use Illuminate\Database\Seeder;

class LicensePlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            ['key' => 'starter', 'name' => 'Starter', 'description' => 'Free tier for a small school.', 'billing_period' => 'free', 'billing_mode' => 'free', 'school_price_paise' => 0, 'student_price_paise' => null, 'parent_price_paise' => null, 'teacher_price_paise' => null, 'student_limit' => 100, 'teacher_limit' => 1, 'ad_supported' => false, 'sort_order' => 1],
            ['key' => 'basic_adware', 'name' => 'Basic Adware', 'description' => 'Free access supported by first-party school-approved sponsor placements.', 'billing_period' => 'free', 'billing_mode' => 'ad_supported', 'school_price_paise' => 0, 'student_price_paise' => null, 'parent_price_paise' => null, 'teacher_price_paise' => null, 'student_limit' => 100, 'teacher_limit' => 1, 'ad_supported' => true, 'sort_order' => 2],
            ['key' => 'professional_school', 'name' => 'Professional', 'description' => 'School-paid annual plan billed per active student.', 'billing_period' => 'annual', 'billing_mode' => 'upfront', 'school_price_paise' => null, 'student_price_paise' => 15000, 'parent_price_paise' => null, 'teacher_price_paise' => null, 'student_limit' => null, 'teacher_limit' => null, 'ad_supported' => false, 'sort_order' => 3],
            ['key' => 'professional_distributed', 'name' => 'Professional Distributed', 'description' => 'Individual annual parent or teacher subscriptions.', 'billing_period' => 'annual', 'billing_mode' => 'upfront', 'school_price_paise' => null, 'student_price_paise' => null, 'parent_price_paise' => 20000, 'teacher_price_paise' => 50000, 'student_limit' => null, 'teacher_limit' => null, 'ad_supported' => false, 'sort_order' => 4],
            ['key' => 'elite', 'name' => 'Elite', 'description' => 'Custom school agreement with configurable services and installments.', 'billing_period' => 'custom', 'billing_mode' => 'installments', 'school_price_paise' => null, 'student_price_paise' => null, 'parent_price_paise' => null, 'teacher_price_paise' => null, 'student_limit' => null, 'teacher_limit' => null, 'ad_supported' => false, 'sort_order' => 5],
        ];

        foreach ($plans as $plan) {
            LicensePlan::query()->firstOrCreate(
                ['key' => $plan['key']],
                [...$plan, 'currency' => 'INR', 'feature_flags' => [], 'is_enabled' => true],
            );
        }
    }
}
