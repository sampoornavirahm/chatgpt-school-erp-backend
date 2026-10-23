<?php

namespace Database\Factories;

use App\Models\ActivationCode;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ActivationCode>
 */
class ActivationCodeFactory extends Factory
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
            'plan_key' => 'professional_school',
            'code_hash' => fake()->sha256(),
            'student_seats' => 100,
            'teacher_seats' => null,
            'feature_flags' => [],
            'code_expires_at' => now()->addDays(30),
            'license_expires_at' => now()->addYear(),
            'consumed_at' => null,
            'consumed_server_id' => null,
            'school_license_id' => null,
            'created_by' => null,
        ];
    }
}
