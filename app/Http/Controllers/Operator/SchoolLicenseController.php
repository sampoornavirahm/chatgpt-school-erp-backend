<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\ActivationCode;
use App\Models\LicensePlan;
use App\Models\School;
use App\Models\SchoolLicense;
use App\Models\SubscriptionContract;
use App\Models\SubscriptionOrder;
use App\Services\BillingService;
use App\Services\PaymentProvider;
use App\Services\SchoolLicenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SchoolLicenseController extends Controller
{
    public function reconcilePayment(Request $request, School $school, SubscriptionOrder $order, BillingService $billing): RedirectResponse
    {
        abort_unless($order->school_id === $school->id, 404);
        $data = $request->validate([
            'method' => ['required', 'in:cheque,bank_transfer,upi,razorpay,payu,cash'],
            'reference' => ['nullable', 'string', 'max:120'],
            'paid_at' => ['required', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $paidOrder = $billing->recordManualPayment($order, $request->user(), $data);
        $this->audit($request, $school, 'manual_payment_reconciled', 'subscription_order', $paidOrder->id, [
            'amountPaise' => $paidOrder->amount_paise,
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
        ]);

        return back()->with('status', 'Payment recorded and the related entitlement was updated.');
    }

    public function extendTrial(Request $request, School $school, SchoolLicenseService $licenses): RedirectResponse
    {
        DB::transaction(function () use ($request, $school, $licenses): void {
            $lockedSchool = School::query()->whereKey($school->id)->lockForUpdate()->firstOrFail();
            $license = $licenses->extendTrial($lockedSchool, $request->user());
            $extensionRequest = DB::table('trial_extension_requests')->where('school_id', $lockedSchool->id)->where('status', 'pending')->orderByDesc('id')->first();
            if ($extensionRequest === null) {
                $schoolAdminId = $lockedSchool->users()->where('role', 'school_admin')->orderBy('id')->value('id');
                if ($schoolAdminId !== null) {
                    $requestId = DB::table('trial_extension_requests')->insertGetId([
                        'school_id' => $lockedSchool->id,
                        'requested_by' => $schoolAdminId,
                        'handled_by' => $request->user()->id,
                        'status' => 'pending',
                        'extension_days' => 15,
                        'school_note' => 'Offline request recorded by the platform operator.',
                        'requested_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $extensionRequest = (object) ['id' => $requestId];
                }
            }
            if ($extensionRequest !== null) {
                DB::table('trial_extension_requests')->where('id', $extensionRequest->id)->update([
                    'status' => 'approved',
                    'handled_by' => $request->user()->id,
                    'handled_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->audit($request, $lockedSchool, 'trial_extended', 'license', $license->id, ['expiresAt' => $license->valid_until?->toIso8601String()]);
        });

        return back()->with('status', 'Trial extension approved and the request recorded. The signed license was refreshed.');
    }

    public function createActivationCode(Request $request, School $school, SchoolLicenseService $licenses): RedirectResponse
    {
        $data = $request->validate([
            'plan_key' => ['required', 'string', Rule::exists('license_plans', 'key')],
            'student_seats' => ['nullable', 'integer', 'min:1', 'max:200000'],
            'teacher_seats' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'feature_flags' => ['nullable', 'string', 'max:2000'],
            'license_expires_at' => ['nullable', 'date', 'after:today'],
        ]);
        $features = $this->features($data['feature_flags'] ?? '');
        $plan = LicensePlan::query()->where('key', $data['plan_key'])->firstOrFail();
        $code = $licenses->createActivationCode(
            $school,
            $request->user(),
            $plan,
            isset($data['student_seats']) ? (int) $data['student_seats'] : null,
            isset($data['teacher_seats']) ? (int) $data['teacher_seats'] : null,
            $features === [] ? null : $features,
            $data['license_expires_at'] ?? null,
        );
        $activation = ActivationCode::query()->where('code_hash', hash('sha256', $code))->firstOrFail();
        $this->audit($request, $school, 'activation_code_issued', 'activation_code', $activation->id, ['plan' => $plan->key]);

        return back()->with('activation_code', $code)->with('status', 'One-time activation code created. Copy it now; the server stores only its hash.');
    }

    public function approveActivation(Request $request, School $school, ActivationCode $activationCode, SchoolLicenseService $licenses): RedirectResponse
    {
        abort_unless($activationCode->school_id === $school->id, 404);
        $license = $licenses->approveActivationCode($activationCode, $request->user());
        $this->audit($request, $school, 'school_enrollment_approved', 'license', $license->id, [
            'activationCodeId' => $activationCode->id,
            'googleEmail' => $activationCode->requested_google_email,
            'plan' => $license->plan_key,
        ]);

        return back()->with('status', 'School enrollment approved and the signed license was issued.');
    }

    public function revoke(Request $request, School $school, SchoolLicense $license): RedirectResponse
    {
        abort_unless($license->school_id === $school->id, 404);
        abort_if($license->status === 'revoked', 409, 'This license has already been revoked.');

        DB::transaction(function () use ($request, $school, $license): void {
            $license->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
            if ($school->current_license_id === $license->id) {
                $school->forceFill(['license_status' => 'suspended'])->save();
            }
            $this->audit($request, $school, 'license_revoked', 'license', $license->id, ['plan' => $license->plan_key]);
        });

        return back()->with('status', 'License revoked. Connected installations will receive the revocation at their next licensing check. Existing school records remain available.');
    }

    public function createEliteContract(Request $request, School $school, PaymentProvider $payments): RedirectResponse
    {
        $data = $request->validate([
            'services_summary' => ['required', 'string', 'min:3', 'max:1000'],
            'installment_amounts' => ['required', 'string', 'max:2000'],
            'installment_due_dates' => ['required', 'string', 'max:2000'],
            'student_seats' => ['nullable', 'integer', 'min:1', 'max:200000'],
            'teacher_seats' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'feature_flags' => ['nullable', 'string', 'max:2000'],
            'license_expires_at' => ['required', 'date', 'after:today'],
        ]);
        $amounts = preg_split('/[,\s]+/', trim($data['installment_amounts']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $dates = preg_split('/[,\s]+/', trim($data['installment_due_dates']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($amounts) < 2 || count($amounts) > 24 || count($amounts) !== count($dates)) {
            throw ValidationException::withMessages(['installment_amounts' => ['Enter matching amounts and due dates for 2 to 24 installments.']]);
        }

        $amountsPaise = [];
        $dueDates = [];
        foreach ($amounts as $index => $amount) {
            if (! preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $amount)) {
                throw ValidationException::withMessages(['installment_amounts' => ['Enter each installment amount in INR with up to two decimals.']]);
            }
            $amountPaise = $this->rupeesToPaise($amount);
            if ($amountPaise < 1) {
                throw ValidationException::withMessages(['installment_amounts' => ['Each Elite installment must be greater than ₹0.00.']]);
            }
            $amountsPaise[] = $amountPaise;
            try {
                $dueDate = Carbon::parse($dates[$index])->startOfDay();
            } catch (\Throwable) {
                throw ValidationException::withMessages(['installment_due_dates' => ['Enter a valid due date for every installment.']]);
            }
            if ($dueDate->toDateString() < today()->toDateString()) {
                throw ValidationException::withMessages(['installment_due_dates' => ['Installment due dates cannot be in the past.']]);
            }
            $dueDates[] = $dueDate;
        }

        $features = $this->features($data['feature_flags'] ?? '');
        $admin = $school->users()->where('role', 'school_admin')->orderBy('id')->firstOrFail();
        $contract = DB::transaction(function () use ($request, $school, $data, $amountsPaise, $dueDates, $features, $admin, $payments): SubscriptionContract {
            $contract = SubscriptionContract::query()->create([
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'plan_key' => 'elite',
                'total_amount_paise' => array_sum($amountsPaise),
                'installment_count' => count($amountsPaise),
                'services_summary' => $data['services_summary'],
                'feature_flags' => $features,
                'student_seats' => isset($data['student_seats']) ? (int) $data['student_seats'] : null,
                'teacher_seats' => isset($data['teacher_seats']) ? (int) $data['teacher_seats'] : null,
                'license_expires_at' => Carbon::parse($data['license_expires_at']),
                'status' => 'pending',
                'created_by' => $request->user()->id,
            ]);

            foreach ($amountsPaise as $index => $amountPaise) {
                $order = SubscriptionOrder::query()->create([
                    'id' => (string) Str::uuid(),
                    'school_id' => $school->id,
                    'user_id' => $admin->id,
                    'contract_id' => $contract->id,
                    'plan_key' => 'elite',
                    'quantity' => 1,
                    'amount_paise' => $amountPaise,
                    'currency' => 'INR',
                    'status' => 'pending',
                    'payment_provider' => $payments->name(),
                    'idempotency_key' => (string) Str::uuid(),
                    'contract_reference' => $contract->id,
                    'installment_number' => $index + 1,
                    'installment_count' => count($amountsPaise),
                    'due_at' => $dueDates[$index],
                ]);
                $order->forceFill($payments->createIntent($order))->save();
            }

            $this->audit($request, $school, 'elite_contract_created', 'subscription_contract', $contract->id, [
                'amountPaise' => $contract->total_amount_paise,
                'installments' => $contract->installment_count,
            ]);

            return $contract;
        });

        return redirect()->route('operator.schools.show', $school)->with('status', 'Elite installment schedule created for ₹'.number_format($contract->total_amount_paise / 100, 2).'.');
    }

    /** @return array<int, string> */
    private function features(string $input): array
    {
        $features = preg_split('/[,\s]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($features as $feature) {
            if (! preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/', $feature)) {
                throw ValidationException::withMessages(['feature_flags' => ['Feature flags may use lowercase letters, numbers, periods, colons, underscores and hyphens.']]);
            }
        }

        return array_values(array_unique($features));
    }

    private function rupeesToPaise(string $amount): int
    {
        [$rupees, $paise] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $rupees * 100) + (int) str_pad($paise, 2, '0');
    }

    /** @param array<string, mixed> $details */
    private function audit(Request $request, School $school, string $action, string $targetType, string $targetId, array $details = []): void
    {
        DB::table('relay_audit_logs')->insert([
            'school_id' => $school->id,
            'actor_user_id' => $request->user()->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
