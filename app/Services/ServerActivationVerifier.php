<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ServerActivationVerifier
{
    /** @return array{activation_id: string} */
    public function activate(string $code, string $schoolId, string $serverId): array
    {
        $url = (string) config('school_erp.activation_url');
        $secret = (string) config('school_erp.activation_shared_secret');
        if (! str_starts_with($url, 'https://') || $secret === '') {
            throw new RuntimeException('The activation service is not configured.');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->withToken($secret)
            ->connectTimeout(3)
            ->timeout(8)
            ->post($url, [
                'activation_code' => $code,
                'school_id' => $schoolId,
                'server_id' => $serverId,
            ]);

        $activationId = $response->json('activation_id');
        if (! $response->successful()
            || $response->json('active') !== true
            || ! is_string($activationId)
            || $activationId === ''
            || strlen($activationId) > 128) {
            throw new RuntimeException('Server activation was not approved.');
        }

        return ['activation_id' => $activationId];
    }
}
