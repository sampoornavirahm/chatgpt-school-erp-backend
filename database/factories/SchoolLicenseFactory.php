<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\SchoolLicense;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SchoolLicense>
 */
class SchoolLicenseFactory extends Factory
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
            'plan_key' => 'starter',
            'status' => 'active',
            'claims' => [],
            'signed_token' => 'test-token',
            'token_hash' => fake()->sha256(),
            'issued_at' => now(),
            'valid_until' => now()->addYear(),
            'grace_until' => now()->addYear()->addDays(7),
            'revoked_at' => null,
            'issued_by' => null,
        ];
    }
}
