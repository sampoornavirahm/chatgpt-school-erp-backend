<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class LicenseTokenService
{
    /** @param array<string, mixed> $claims */
    public function sign(array $claims): string
    {
        [$secretKey] = $this->keyPair();
        $payload = $this->encode(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signature = sodium_crypto_sign_detached($payload, $secretKey);

        return $payload.'.'.$this->encode($signature);
    }

    /** @return array{valid: bool, state?: string, claims?: array<string, mixed>} */
    public function verify(string $token): array
    {
        try {
            [, $publicKey] = $this->keyPair();
            $parts = explode('.', $token);
            if (count($parts) !== 2) {
                return ['valid' => false];
            }

            [$payload, $signature] = $parts;
            $decodedPayload = $this->decode($payload);
            $decodedSignature = $this->decode($signature);
            if ($decodedPayload === null || $decodedSignature === null
                || strlen($decodedSignature) !== SODIUM_CRYPTO_SIGN_BYTES
                || ! sodium_crypto_sign_verify_detached($decodedSignature, $payload, $publicKey)) {
                return ['valid' => false];
            }

            $claims = json_decode($decodedPayload, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($claims)
                || ! isset($claims['license_id'], $claims['school_id'], $claims['server_id'], $claims['plan'], $claims['issued_at'])
                || ! is_array($claims['limits'] ?? null)
                || ($claims['issuer'] ?? null) !== config('school_erp.license_issuer')
                || ($claims['key_id'] ?? null) !== config('school_erp.license_key_id')
                || ($claims['token_version'] ?? null) !== 1) {
                return ['valid' => false];
            }

            $now = now()->timestamp;
            $validUntil = isset($claims['expires_at']) ? strtotime((string) $claims['expires_at']) : null;
            $graceUntil = isset($claims['grace_until']) ? strtotime((string) $claims['grace_until']) : null;
            if ((isset($claims['expires_at']) && $validUntil === false)
                || (isset($claims['grace_until']) && $graceUntil === false)) {
                return ['valid' => false];
            }
            $state = $validUntil === null || $now <= $validUntil
                ? 'active'
                : ($graceUntil !== null && $now <= $graceUntil ? 'grace' : 'expired');

            return ['valid' => true, 'state' => $state, 'claims' => $claims];
        } catch (Throwable) {
            return ['valid' => false];
        }
    }

    public function publicKey(): string
    {
        [, $publicKey] = $this->keyPair();

        return $this->encode($publicKey);
    }

    /** @return array{0: string, 1: string} */
    public function generateKeyPair(bool $failIfExists = true): array
    {
        $privatePath = (string) config('school_erp.license_private_key_path');
        $publicPath = (string) config('school_erp.license_public_key_path');
        if ($failIfExists && (File::exists($privatePath) || File::exists($publicPath))) {
            throw new RuntimeException('A license signing key already exists. Choose a new key path for rotation.');
        }

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        foreach ([$privatePath, $publicPath] as $path) {
            File::ensureDirectoryExists(dirname($path), 0700, true);
        }
        File::put($privatePath, base64_encode($secretKey));
        File::put($publicPath, base64_encode($publicKey));
        @chmod($privatePath, 0600);
        @chmod($publicPath, 0644);

        return [$privatePath, $publicPath];
    }

    /** @return array{0: string, 1: string} */
    private function keyPair(): array
    {
        $privatePath = (string) config('school_erp.license_private_key_path');
        $publicPath = (string) config('school_erp.license_public_key_path');
        if (! File::exists($privatePath)) {
            if (app()->environment(['local', 'testing']) && config('school_erp.license_auto_generate_local_keys')) {
                $this->generateKeyPair(false);
            } else {
                throw new RuntimeException('The license signing key is not configured.');
            }
        }

        $secretKey = base64_decode(trim(File::get($privatePath)), true);
        if ($secretKey === false || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The license signing key is invalid.');
        }
        $publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);
        if (File::exists($publicPath)) {
            $configuredPublicKey = base64_decode(trim(File::get($publicPath)), true);
            if ($configuredPublicKey === false || ! hash_equals($publicKey, $configuredPublicKey)) {
                throw new RuntimeException('The public license verification key does not match the signing key.');
            }
        } else {
            File::ensureDirectoryExists(dirname($publicPath), 0700, true);
            File::put($publicPath, base64_encode($publicKey));
            @chmod($publicPath, 0644);
        }

        return [$secretKey, $publicKey];
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): ?string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
