<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id',
    'name',
    'server_id',
    'google_subject',
    'google_email',
    'google_link_status',
    'activation_status',
    'activation_id',
    'activated_at',
    'trial_started_at',
    'trial_expires_at',
    'trial_extended_at',
    'current_license_id',
    'relay_status',
    'storage_quota_bytes',
    'license_plan_key',
    'license_status',
    'active_student_limit',
    'active_teacher_limit',
    'license_expires_at',
    'trial_extension_requested_at',
    'service_on_hold',
    'service_on_hold_at',
    'hold_previous_relay_status',
    'archive_access_enabled',
    'archive_access_starts_at',
    'archive_access_ends_at',
    'archive_free_reads_limit',
    'archive_query_price_paise',
    'archive_access_note',
])]
class School extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'trial_started_at' => 'datetime',
            'trial_expires_at' => 'datetime',
            'trial_extended_at' => 'datetime',
            'activated_at' => 'datetime',
            'license_expires_at' => 'datetime',
            'trial_extension_requested_at' => 'datetime',
            'service_on_hold' => 'boolean',
            'service_on_hold_at' => 'datetime',
            'archive_access_enabled' => 'boolean',
            'archive_access_starts_at' => 'datetime',
            'archive_access_ends_at' => 'datetime',
            'archive_free_reads_limit' => 'integer',
            'archive_query_price_paise' => 'integer',
            'storage_quota_bytes' => 'integer',
            'active_student_limit' => 'integer',
            'active_teacher_limit' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(SchoolLicense::class);
    }

    public function currentLicense(): BelongsTo
    {
        return $this->belongsTo(SchoolLicense::class, 'current_license_id');
    }

    public function trialExtensionRequests(): HasMany
    {
        return $this->hasMany(TrialExtensionRequest::class);
    }
}
