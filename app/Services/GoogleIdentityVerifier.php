<?php

namespace App\Services;

use Google\Client;
use GuzzleHttp\Client as GuzzleClient;
use RuntimeException;

class GoogleIdentityVerifier
{
    /** @return array{sub: string, email: string, email_verified: bool} */
    public function verify(string $idToken): array
    {
        $clientId = (string) config('school_erp.google_client_id');
        if ($clientId === '') {
            throw new RuntimeException('Google sign-in is not configured.');
        }

        $client = new Client(['client_id' => $clientId]);
        $client->setHttpClient(new GuzzleClient([
            'connect_timeout' => 3,
            'timeout' => 8,
        ]));
        $claims = $client->verifyIdToken($idToken);
        if (! is_array($claims)
            || ! isset($claims['sub'], $claims['email'])
            || ($claims['email_verified'] ?? false) !== true) {
            throw new RuntimeException('Google account could not be verified.');
        }

        return [
            'sub' => (string) $claims['sub'],
            'email' => (string) $claims['email'],
            'email_verified' => true,
        ];
    }
}
