<?php

namespace App\Models;

use Database\Factories\UserEntitlementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'id', 'school_id', 'user_id', 'plan_key', 'status', 'feature_flags', 'starts_at', 'expires_at', 'revoked_at',
])]
class UserEntitlement extends Model
{
    /** @use HasFactory<UserEntitlementFactory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'feature_flags' => 'array',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
