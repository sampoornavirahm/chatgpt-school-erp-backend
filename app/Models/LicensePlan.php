<?php

namespace App\Models;

use Database\Factories\LicensePlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'key', 'name', 'description', 'currency', 'billing_period', 'billing_mode',
    'school_price_paise', 'student_price_paise', 'parent_price_paise', 'teacher_price_paise',
    'student_limit', 'teacher_limit', 'feature_flags', 'ad_supported', 'is_enabled', 'sort_order',
])]
class LicensePlan extends Model
{
    /** @use HasFactory<LicensePlanFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'school_price_paise' => 'integer',
            'student_price_paise' => 'integer',
            'parent_price_paise' => 'integer',
            'teacher_price_paise' => 'integer',
            'student_limit' => 'integer',
            'teacher_limit' => 'integer',
            'feature_flags' => 'array',
            'ad_supported' => 'boolean',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
