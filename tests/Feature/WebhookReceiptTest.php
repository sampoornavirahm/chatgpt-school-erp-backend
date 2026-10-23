<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_forged_events_are_rejected_and_valid_webhook_receipts_are_idempotent(): void
    {
        $secret = str_repeat('webhook-secret-', 4);
        config(['school_erp.webhook_shared_secret' => $secret]);
        $body = json_encode(['event' => 'payment.updated', 'data' => ['status' => 'paid']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $eventId = 'evt_20261021_001';
        $timestamp = (string) now()->timestamp;
        $headers = $this->headers('testpay', $eventId, $timestamp, $body, $secret);

        $this->call('POST', '/api/v1/webhooks/testpay', [], [], [], [...$headers, 'HTTP_X_WEBHOOK_SIGNATURE' => str_repeat('0', 64)], $body)->assertUnauthorized();
        $this->call('POST', '/api/v1/webhooks/testpay', [], [], [], $headers, $body)
            ->assertAccepted()->assertJsonPath('processed', false);
        $this->call('POST', '/api/v1/webhooks/testpay', [], [], [], $headers, $body)
            ->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('relay_webhook_receipts', 1);
        $this->assertDatabaseHas('relay_webhook_receipts', ['provider' => 'testpay', 'event_id' => $eventId, 'status' => 'received_unprocessed']);

        $changedBody = json_encode(['event' => 'payment.updated', 'data' => ['status' => 'refunded']], JSON_THROW_ON_ERROR);
        $changedHeaders = $this->headers('testpay', $eventId, $timestamp, $changedBody, $secret);
        $this->call('POST', '/api/v1/webhooks/testpay', [], [], [], $changedHeaders, $changedBody)->assertConflict()->assertJsonPath('error.code', 'WEBHOOK_EVENT_REPLAY_MISMATCH');
    }

    public function test_webhook_endpoint_fails_closed_when_no_secret_is_configured(): void
    {
        config(['school_erp.webhook_shared_secret' => null]);

        $this->postJson('/api/v1/webhooks/testpay', ['event' => 'payment.updated'])->assertServiceUnavailable()->assertJsonPath('error.code', 'WEBHOOKS_NOT_CONFIGURED');
    }

    private function headers(string $provider, string $eventId, string $timestamp, string $body, string $secret): array
    {
        $payloadHash = hash('sha256', $body);
        $signedContent = implode("\n", [$provider, $timestamp, $eventId, $payloadHash]);

        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_X_WEBHOOK_EVENT_ID' => $eventId,
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $signedContent, $secret),
        ];
    }
}
