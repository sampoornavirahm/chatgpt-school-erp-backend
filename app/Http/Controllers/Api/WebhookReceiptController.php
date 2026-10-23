<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebhookReceiptController extends Controller
{
    public function store(Request $request, string $provider): JsonResponse
    {
        $secret = (string) config('school_erp.webhook_shared_secret');
        if (strlen($secret) < 32) {
            return response()->json(['error' => ['code' => 'WEBHOOKS_NOT_CONFIGURED', 'message' => 'Webhook verification is not configured.']], 503);
        }
        $timestamp = $request->header('X-Webhook-Timestamp');
        $eventId = $request->header('X-Webhook-Event-ID');
        $signature = $request->header('X-Webhook-Signature');
        if (! preg_match('/^[a-z0-9_-]{2,40}$/', $provider)
            || ! is_string($timestamp) || ! ctype_digit($timestamp)
            || abs(now()->timestamp - (int) $timestamp) > 300
            || ! is_string($eventId) || ! preg_match('/^[A-Za-z0-9._:-]{1,180}$/', $eventId)
            || ! is_string($signature) || ! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return response()->json(['error' => ['code' => 'WEBHOOK_AUTH_INVALID', 'message' => 'Webhook authentication failed.']], 401);
        }

        $payloadHash = hash('sha256', $request->getContent());
        $signedContent = implode("\n", [$provider, $timestamp, $eventId, $payloadHash]);
        if (! hash_equals(hash_hmac('sha256', $signedContent, $secret), $signature)) {
            return response()->json(['error' => ['code' => 'WEBHOOK_AUTH_INVALID', 'message' => 'Webhook authentication failed.']], 401);
        }

        $inserted = DB::table('relay_webhook_receipts')->insertOrIgnore([
            'provider' => $provider,
            'event_id' => $eventId,
            'payload_hash' => $payloadHash,
            'status' => 'received_unprocessed',
            'received_at' => now(),
        ]);
        $receipt = DB::table('relay_webhook_receipts')->where('provider', $provider)->where('event_id', $eventId)->first();
        if ($receipt === null) {
            return response()->json(['error' => ['code' => 'WEBHOOK_RECEIPT_FAILED', 'message' => 'The webhook could not be recorded.']], 503);
        }
        if (! hash_equals($receipt->payload_hash, $payloadHash)) {
            return response()->json(['error' => ['code' => 'WEBHOOK_EVENT_REPLAY_MISMATCH', 'message' => 'This event ID was already recorded with a different payload.']], 409);
        }
        if ($inserted === 0) {
            return response()->json(['received' => true, 'duplicate' => true, 'processed' => false]);
        }

        return response()->json(['received' => true, 'duplicate' => false, 'processed' => false], 202);
    }
}
