<?php

namespace App\Services;

use RuntimeException;

class DummyActivationProvider implements ActivationProvider
{
    public function activate(string $code, string $schoolId, string $serverId): array
    {
        if (! app()->environment(['local', 'testing']) || ! config('school_erp.allow_dummy_activations')) {
            throw new RuntimeException('Dummy activation is disabled outside local and test environments.');
        }
        if (trim($code) === '') {
            throw new RuntimeException('An activation code is required.');
        }

        $activationId = 'dummy-'.substr(hash('sha256', implode("\n", [$code, $schoolId, $serverId])), 0, 32);

        return ['activation_id' => $activationId];
    }
}
