<?php

namespace App\Models;

use Database\Factories\ActivationCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'id', 'school_id', 'plan_key', 'code_hash', 'student_seats', 'teacher_seats', 'feature_flags',
    'code_expires_at', 'requested_server_id', 'requested_google_subject', 'requested_google_email',
    'enrollment_requested_at', 'approved_at', 'approved_by', 'license_expires_at', 'consumed_at',
    'consumed_server_id', 'school_license_id', 'created_by',
])]
#[Hidden(['code_hash'])]
class ActivationCode extends Model
{
    /** @use HasFactory<ActivationCodeFactory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'student_seats' => 'integer',
            'teacher_seats' => 'integer',
            'feature_flags' => 'array',
            'code_expires_at' => 'datetime',
            'enrollment_requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'license_expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
