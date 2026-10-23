<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionOrder;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'orders' => SubscriptionOrder::query()->where('school_id', $user->school_id)->where('user_id', $user->id)->latest()->limit(50)->get([
                'id', 'plan_key', 'quantity', 'amount_paise', 'currency', 'status', 'payment_provider',
                'payment_uri', 'installment_number', 'installment_count', 'due_at', 'paid_at', 'created_at',
            ]),
            'entitlements' => UserEntitlement::query()->where('school_id', $user->school_id)->where('user_id', $user->id)->get([
                'plan_key', 'status', 'feature_flags', 'starts_at', 'expires_at', 'revoked_at',
            ]),
        ]);
    }

    public function store(Request $request, BillingService $billing): JsonResponse
    {
        $data = $request->validate([
            'plan_key' => ['required', 'string', 'max:40'],
            'idempotency_key' => ['required', 'uuid'],
            'student_seats' => ['sometimes', 'integer', 'min:1', 'max:200000'],
        ]);
        $result = $billing->createOrder($request->user(), $data);

        return response()->json([
            'duplicate' => $result['duplicate'],
            'order' => $this->orderPayload($result['order']),
            'payment_mode' => 'merchant_upi_qr',
            'qr_ready' => $result['order']->payment_uri !== null,
        ], $result['duplicate'] ? 200 : 201);
    }

    public function simulatePayment(Request $request, SubscriptionOrder $order, BillingService $billing): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($order->school_id === $user->school_id && $order->user_id === $user->id, 404);
        $data = $request->validate(['outcome' => ['sometimes', 'in:paid,failed']]);
        $result = $billing->simulatePayment($order, $data['outcome'] ?? 'paid');

        return response()->json([
            'duplicate' => $result['duplicate'],
            'order' => $this->orderPayload($result['order']),
        ]);
    }

    /** @return array<string, mixed> */
    private function orderPayload(SubscriptionOrder $order): array
    {
        return [
            'id' => $order->id,
            'school_id' => $order->school_id,
            'user_id' => $order->user_id,
            'plan_key' => $order->plan_key,
            'quantity' => $order->quantity,
            'amount_paise' => $order->amount_paise,
            'currency' => $order->currency,
            'status' => $order->status,
            'payment_provider' => $order->payment_provider,
            'provider_order_id' => $order->provider_order_id,
            'payment_uri' => $order->payment_uri,
            'installment_number' => $order->installment_number,
            'installment_count' => $order->installment_count,
            'due_at' => $order->due_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
        ];
    }
}
