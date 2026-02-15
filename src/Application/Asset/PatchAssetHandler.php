<?php

namespace App\Application\Asset;

use App\Application\Asset\Port\AssetPatchGateway;

final class PatchAssetHandler
{
    public function __construct(
        private AssetPatchGateway $gateway,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(string $uuid, array $payload): PatchAssetResult
    {
        $result = $this->gateway->patch($uuid, $payload);

        return new PatchAssetResult($result['status'], $result['payload']);
    }
}
