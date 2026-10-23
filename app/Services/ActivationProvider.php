<?php

namespace App\Services;

interface ActivationProvider
{
    /** @return array{activation_id: string} */
    public function activate(string $code, string $schoolId, string $serverId): array;
}
