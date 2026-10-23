<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\LicensePlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LicensePlanController extends Controller
{
    public function index(): View
    {
        $paymentSettings = DB::table('platform_payment_settings')->where('id', 1)->first();

        return view('operator.license-plans', [
            'plans' => LicensePlan::query()->orderBy('sort_order')->orderBy('id')->get(),
            'merchantVpaConfigured' => (bool) ($paymentSettings?->merchant_vpa ?: config('school_erp.merchant_upi_vpa')),
        ]);
    }

    public function update(Request $request, LicensePlan $licensePlan): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'billing_period' => ['required', 'in:free,annual,custom'],
            'billing_mode' => ['required', 'in:free,ad_supported,upfront,installments'],
            'school_price_inr' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'student_price_inr' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'parent_price_inr' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'teacher_price_inr' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'student_limit' => ['nullable', 'integer', 'min:1', 'max:200000'],
            'teacher_limit' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'feature_flags' => ['nullable', 'string', 'max:2000'],
            'ad_supported' => ['sometimes', 'boolean'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        $features = preg_split('/[,\s]+/', trim($data['feature_flags'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($features as $feature) {
            if (! preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/', $feature)) {
                throw ValidationException::withMessages(['feature_flags' => ['Feature flags may use lowercase letters, numbers, periods, colons, underscores and hyphens.']]);
            }
        }

        $studentLimit = $data['student_limit'] ?? null;
        $teacherLimit = $data['teacher_limit'] ?? null;
        if ($licensePlan->key === 'basic_adware') {
            $starter = LicensePlan::query()->where('key', 'starter')->first();
            $studentLimit ??= $starter?->student_limit ?? 100;
            $teacherLimit ??= $starter?->teacher_limit ?? 1;
        }

        $licensePlan->forceFill([
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'currency' => 'INR',
            'billing_period' => $data['billing_period'],
            'billing_mode' => $data['billing_mode'],
            'school_price_paise' => $this->rupeesToPaise($data['school_price_inr'] ?? null),
            'student_price_paise' => $this->rupeesToPaise($data['student_price_inr'] ?? null),
            'parent_price_paise' => $this->rupeesToPaise($data['parent_price_inr'] ?? null),
            'teacher_price_paise' => $this->rupeesToPaise($data['teacher_price_inr'] ?? null),
            'student_limit' => $studentLimit,
            'teacher_limit' => $teacherLimit,
            'feature_flags' => array_values(array_unique($features)),
            'ad_supported' => $request->boolean('ad_supported'),
            'is_enabled' => $request->boolean('is_enabled'),
        ])->save();

        DB::table('relay_audit_logs')->insert([
            'school_id' => null,
            'actor_user_id' => $request->user()->id,
            'action' => 'license_plan_updated',
            'target_type' => 'license_plan',
            'target_id' => $licensePlan->key,
            'details' => json_encode(['name' => $licensePlan->name, 'billingPeriod' => $licensePlan->billing_period], JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return back()->with('status', $licensePlan->name.' settings saved.');
    }

    private function rupeesToPaise(?string $amount): ?int
    {
        if ($amount === null || trim($amount) === '') {
            return null;
        }
        $normalized = number_format((float) $amount, 2, '.', '');

        return (int) str_replace('.', '', $normalized);
    }
}
