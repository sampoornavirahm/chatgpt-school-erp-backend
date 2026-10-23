<?php

namespace App\Models;

use Database\Factories\SchoolLicenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id', 'school_id', 'plan_key', 'status', 'claims', 'signed_token', 'token_hash',
    'issued_at', 'valid_until', 'grace_until', 'revoked_at', 'issued_by',
])]
class SchoolLicense extends Model
{
    /** @use HasFactory<SchoolLicenseFactory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'claims' => 'array',
            'issued_at' => 'datetime',
            'valid_until' => 'datetime',
            'grace_until' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
