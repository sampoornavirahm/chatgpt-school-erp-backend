<?php

namespace App\Models;

use Database\Factories\SubscriptionContractFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id', 'school_id', 'plan_key', 'total_amount_paise', 'installment_count', 'services_summary',
    'feature_flags', 'student_seats', 'teacher_seats', 'license_expires_at', 'status', 'school_license_id', 'created_by',
])]
class SubscriptionContract extends Model
{
    /** @use HasFactory<SubscriptionContractFactory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'total_amount_paise' => 'integer',
            'installment_count' => 'integer',
            'feature_flags' => 'array',
            'student_seats' => 'integer',
            'teacher_seats' => 'integer',
            'license_expires_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SubscriptionOrder::class, 'contract_id');
    }
}
