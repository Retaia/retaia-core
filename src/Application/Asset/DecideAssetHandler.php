<?php

namespace App\Application\Asset;

use App\Application\Asset\Port\AssetWorkflowGateway;

final class DecideAssetHandler
{
    public function __construct(
        private AssetWorkflowGateway $gateway,
    ) {
    }

    public function handle(string $uuid, string $action): DecideAssetResult
    {
        $result = $this->gateway->decide($uuid, $action);

        return new DecideAssetResult($result['status'], $result['payload']);
    }
}
