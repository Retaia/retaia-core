<?php

namespace App\Application\Asset\Port;

interface AssetWorkflowGateway
{
    /**
     * @return array{status: string, payload: array<string, mixed>|null}
     */
    public function decide(string $uuid, string $action): array;

    /**
     * @return array{status: string, payload: array<string, mixed>|null}
     */
    public function reopen(string $uuid): array;

    /**
     * @return array{status: string, payload: array<string, mixed>|null}
     */
    public function reprocess(string $uuid): array;
}
