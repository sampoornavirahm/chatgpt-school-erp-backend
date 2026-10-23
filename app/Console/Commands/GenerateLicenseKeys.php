<?php

namespace App\Console\Commands;

use App\Services\LicenseTokenService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('license:generate-keys')]
#[Description('Generate an Ed25519 license signing key pair outside the public web directory.')]
class GenerateLicenseKeys extends Command
{
    public function handle(LicenseTokenService $tokens): int
    {
        try {
            [, $publicPath] = $tokens->generateKeyPair();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('License key pair generated. Private key stays in the configured private storage path.');
        $this->line('Public key file: '.$publicPath);
        $this->line('Key ID: '.config('school_erp.license_key_id'));

        return self::SUCCESS;
    }
}
