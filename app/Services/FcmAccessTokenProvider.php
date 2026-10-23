<?php

namespace App\Services;

use Google\Client;
use GuzzleHttp\Client as GuzzleClient;
use RuntimeException;
use Throwable;

class FcmAccessTokenProvider
{
    public function accessToken(string $serviceAccountPath): string
    {
        try {
            $client = new Client;
            $client->setHttpClient(new GuzzleClient([
                'connect_timeout' => 3,
                'timeout' => 8,
            ]));
            $client->setAuthConfig($serviceAccountPath);
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
            $access = $client->fetchAccessTokenWithAssertion();
            $accessToken = $access['access_token'] ?? null;
        } catch (Throwable) {
            throw new RuntimeException('FCM credentials could not be used.');
        }

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('FCM credentials could not be used.');
        }

        return $accessToken;
    }
}
