<?php

namespace App\Services;

use App\Models\LicensePlan;
use App\Models\School;
use App\Models\SubscriptionContract;
use App\Models\SubscriptionOrder;
use App\Models\User;
use App\Models\UserEntitlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BillingService
{
    public function __construct(
        private PaymentProvider $payments,
        private SchoolLicenseService $licenses,
    ) {}

    /** @param array{plan_key: string, idempotency_key: string, student_seats?: int|null} $data */
    public function createOrder(User $user, array $data): array
    {
        $plan = LicensePlan::query()->where('key', $data['plan_key'])->where('is_enabled', true)->first();
        if ($plan === null) {
            throw ValidationException::withMessages(['plan_key' => ['Choose an available paid plan.']]);
        }

        $school = $user->school;
        if ($school === null) {
            throw ValidationException::withMessages(['plan_key' => ['A school account is required for this purchase.']]);
        }

        $quantity = 1;
        if ($plan->key === 'professional_school' && $user->role === 'school_admin') {
            $activeStudents = (int) DB::table('relay_snapshots')->where('school_id', $school->id)->where('is_deleted', false)->count();
            $quantity = max($activeStudents, (int) ($data['student_seats'] ?? 0));
            if ($quantity < 1 || $plan->student_price_paise === null) {
                throw ValidationException::withMessages(['plan_key' => ['Sync at least one active student before purchasing this school plan.']]);
            }
            $amount = $quantity * $plan->student_price_paise;
        } elseif ($plan->key === 'professional_distributed' && in_array($user->role, ['parent', 'teacher'], true)) {
            $unitAmount = $user->role === 'parent' ? $plan->parent_price_paise : $plan->teacher_price_paise;
            if ($unitAmount === null) {
                throw ValidationException::withMessages(['plan_key' => ['This role does not have a configured price for the selected plan.']]);
            }
            $amount = $unitAmount;
        } else {
            throw ValidationException::withMessages(['plan_key' => ['This plan is not available to your account.']]);
        }

        $existing = SubscriptionOrder::query()->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing !== null) {
            if ($existing->school_id !== $school->id || $existing->user_id !== $user->id || $existing->plan_key !== $plan->key) {
                throw new ConflictHttpException('This request key has already been used.');
            }

            return ['order' => $existing, 'duplicate' => true];
        }

        $order = SubscriptionOrder::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'user_id' => $user->id,
            'plan_key' => $plan->key,
            'quantity' => $quantity,
            'amount_paise' => $amount,
            'currency' => $plan->currency,
            'status' => 'pending',
            'payment_provider' => $this->payments->name(),
            'idempotency_key' => $data['idempotency_key'],
        ]);
        $intent = $this->payments->createIntent($order);
        $order->forceFill($intent)->save();

        return ['order' => $order->refresh(), 'duplicate' => false];
    }

    /** @return array{order: SubscriptionOrder, duplicate: bool} */
    public function simulatePayment(SubscriptionOrder $order, string $outcome = 'paid'): array
    {
        if (! app()->environment(['local', 'testing']) || ! config('school_erp.allow_dummy_payments')
            || $order->payment_provider !== 'dummy') {
            abort(503, 'Dummy payment simulation is disabled.');
        }
        if (! in_array($outcome, ['paid', 'failed'], true)) {
            throw ValidationException::withMessages(['outcome' => ['Choose a supported test payment result.']]);
        }

        return DB::transaction(function () use ($order, $outcome): array {
            $lockedOrder = SubscriptionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($lockedOrder->status === 'paid') {
                return ['order' => $lockedOrder, 'duplicate' => true];
            }
            if ($lockedOrder->status !== 'pending') {
                throw new ConflictHttpException('This order is no longer pending.');
            }

            $eventId = 'dummy-'.$outcome.'-'.$lockedOrder->id;
            $payloadHash = hash('sha256', implode('|', [
                $lockedOrder->id,
                $lockedOrder->amount_paise,
                $lockedOrder->currency,
                $outcome,
            ]));
            $inserted = DB::table('payment_events')->insertOrIgnore([
                'subscription_order_id' => $lockedOrder->id,
                'provider' => 'dummy',
                'event_id' => $eventId,
                'payload_hash' => $payloadHash,
                'status' => 'processing',
                'received_at' => now(),
            ]);
            $event = DB::table('payment_events')->where('provider', 'dummy')->where('event_id', $eventId)->first();
            if ($event === null || ! hash_equals($event->payload_hash, $payloadHash)) {
                throw new ConflictHttpException('This payment event was already received with different details.');
            }
            if ($inserted === 0) {
                return ['order' => $lockedOrder, 'duplicate' => true];
            }

            $lockedOrder->forceFill([
                'status' => $outcome,
                'paid_at' => $outcome === 'paid' ? now() : null,
            ])->save();

            if ($outcome === 'paid') {
                $this->grantEntitlement($lockedOrder);
            }

            DB::table('payment_events')->where('id', $event->id)->update(['status' => 'processed', 'processed_at' => now()]);

            return ['order' => $lockedOrder->refresh(), 'duplicate' => false];
        });
    }

    /** @param array{method: string, reference?: string|null, paid_at: string, note?: string|null} $details */
    public function recordManualPayment(SubscriptionOrder $order, User $operator, array $details): SubscriptionOrder
    {
        return DB::transaction(function () use ($order, $operator, $details): SubscriptionOrder {
            $lockedOrder = SubscriptionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($lockedOrder->status !== 'pending') {
                throw new ConflictHttpException('Only pending orders can be reconciled.');
            }
            DB::table('payment_reconciliations')->insert([
                'subscription_order_id' => $lockedOrder->id,
                'recorded_by' => $operator->id,
                'method' => $details['method'],
                'reference' => $details['reference'] ?? null,
                'amount_paise' => $lockedOrder->amount_paise,
                'paid_at' => $details['paid_at'],
                'note' => $details['note'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $lockedOrder->forceFill([
                'status' => 'paid',
                'payment_provider' => 'manual',
                'paid_at' => $details['paid_at'],
            ])->save();
            $this->grantEntitlement($lockedOrder);

            return $lockedOrder->refresh();
        });
    }

    private function grantEntitlement(SubscriptionOrder $order): void
    {
        $plan = LicensePlan::query()->where('key', $order->plan_key)->firstOrFail();
        $school = School::query()->whereKey($order->school_id)->lockForUpdate()->firstOrFail();
        if ($plan->key === 'professional_school') {
            $currentExpiry = $school->license_expires_at;
            $startsFrom = $currentExpiry !== null && $currentExpiry->isFuture() ? $currentExpiry : now();
            $this->licenses->issue(
                $school,
                $plan,
                $order->quantity,
                $plan->teacher_limit,
                $startsFrom->copy()->addYear(),
                $order->user,
            );

            return;
        }

        if ($plan->key === 'elite') {
            $contract = SubscriptionContract::query()->whereKey($order->contract_id)->lockForUpdate()->firstOrFail();
            $paidInstallments = SubscriptionOrder::query()->where('contract_id', $contract->id)->where('status', 'paid')->count();
            if ($paidInstallments < $contract->installment_count || $contract->status === 'active') {
                return;
            }

            $license = $this->licenses->issue(
                $school,
                $plan,
                $contract->student_seats,
                $contract->teacher_seats,
                $contract->license_expires_at,
                $contract->creator,
                false,
                $contract->feature_flags,
                $contract->services_summary,
            );
            $contract->forceFill(['status' => 'active', 'school_license_id' => $license->id])->save();

            return;
        }

        $entitlement = UserEntitlement::query()->where('school_id', $school->id)
            ->where('user_id', $order->user_id)
            ->where('plan_key', $plan->key)
            ->lockForUpdate()
            ->first();
        $startsAt = $entitlement?->starts_at ?? now();
        $renewalFrom = $entitlement?->expires_at !== null && $entitlement->expires_at->isFuture()
            ? $entitlement->expires_at
            : now();
        $entitlement ??= new UserEntitlement(['id' => (string) Str::uuid()]);
        $entitlement->forceFill([
            'school_id' => $school->id,
            'user_id' => $order->user_id,
            'plan_key' => $plan->key,
            'status' => 'active',
            'feature_flags' => $plan->feature_flags ?? [],
            'starts_at' => $startsAt,
            'expires_at' => $renewalFrom->copy()->addYear(),
            'revoked_at' => null,
        ])->save();
    }
}
