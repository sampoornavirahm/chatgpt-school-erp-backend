<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\SubscriptionOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionOrder>
 */
class SubscriptionOrderFactory extends Factory
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
            'contract_id' => null,
            'plan_key' => 'professional_distributed',
            'quantity' => 1,
            'amount_paise' => 20000,
            'currency' => 'INR',
            'status' => 'pending',
            'payment_provider' => 'dummy',
            'provider_order_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'payment_uri' => null,
            'contract_reference' => null,
            'installment_number' => null,
            'installment_count' => null,
            'due_at' => null,
            'paid_at' => null,
        ];
    }
}
