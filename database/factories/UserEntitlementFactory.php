<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\User;
use App\Models\UserEntitlement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserEntitlement>
 */
class UserEntitlementFactory extends Factory
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
            'user_id' => User::factory(),
            'plan_key' => 'professional_distributed',
            'status' => 'active',
            'feature_flags' => [],
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
            'revoked_at' => null,
        ];
    }
}
