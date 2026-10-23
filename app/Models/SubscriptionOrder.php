<?php

namespace App\Models;

use Database\Factories\SubscriptionOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id', 'school_id', 'user_id', 'plan_key', 'quantity', 'amount_paise', 'currency', 'status',
    'contract_id', 'payment_provider', 'provider_order_id', 'idempotency_key', 'payment_uri', 'contract_reference',
    'installment_number', 'installment_count', 'due_at', 'paid_at',
])]
class SubscriptionOrder extends Model
{
    /** @use HasFactory<SubscriptionOrderFactory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'amount_paise' => 'integer',
            'installment_number' => 'integer',
            'installment_count' => 'integer',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(SubscriptionContract::class, 'contract_id');
    }
}
