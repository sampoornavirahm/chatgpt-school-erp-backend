<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'school_id', 'requested_by', 'handled_by', 'status', 'extension_days',
    'school_note', 'operator_note', 'requested_at', 'handled_at',
])]
class TrialExtensionRequest extends Model
{
    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'handled_at' => 'datetime', 'extension_days' => 'integer'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
