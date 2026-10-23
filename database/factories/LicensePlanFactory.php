<?php

namespace Database\Factories;

use App\Models\LicensePlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LicensePlan>
 */
class LicensePlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'currency' => 'INR',
            'billing_period' => 'annual',
            'billing_mode' => 'upfront',
            'school_price_paise' => null,
            'student_price_paise' => 15000,
            'parent_price_paise' => null,
            'teacher_price_paise' => null,
            'student_limit' => null,
            'teacher_limit' => null,
            'feature_flags' => [],
            'ad_supported' => false,
            'is_enabled' => true,
            'sort_order' => 10,
        ];
    }
}
