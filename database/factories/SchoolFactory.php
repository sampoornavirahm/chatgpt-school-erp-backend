<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
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
            'name' => fake()->company().' School',
            'server_id' => (string) Str::uuid(),
            'google_link_status' => 'bypassed_trial',
            'activation_status' => 'bypassed_trial',
            'trial_started_at' => now(),
            'trial_expires_at' => now()->addDays(15),
            'relay_status' => 'active',
            'storage_quota_bytes' => 53687091200,
            'license_plan_key' => 'starter',
            'license_status' => 'trial',
            'active_student_limit' => 100,
            'active_teacher_limit' => 1,
        ];
    }
}
