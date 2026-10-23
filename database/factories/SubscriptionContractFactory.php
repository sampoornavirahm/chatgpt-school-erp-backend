<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\SubscriptionContract;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionContract>
 */
class SubscriptionContractFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'school_id' => School::factory(),
            'plan_key' => 'elite',
            'total_amount_paise' => 100000,
            'installment_count' => 2,
            'services_summary' => 'Custom school services agreement',
            'feature_flags' => [],
            'student_seats' => null,
            'teacher_seats' => null,
            'license_expires_at' => now()->addYear(),
            'status' => 'pending',
            'school_license_id' => null,
            'created_by' => null,
        ];
    }
}
