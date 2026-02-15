<?php

namespace App\Application\Asset\Port;

interface AssetPatchGateway
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return array{status: string, payload: array<string, mixed>|null}
     */
    public function patch(string $uuid, array $payload): array;
}
