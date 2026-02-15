<?php

namespace App\Application\Asset;

use App\Application\Asset\Port\AssetWorkflowGateway;

final class ReprocessAssetHandler
{
    public function __construct(
        private AssetWorkflowGateway $gateway,
    ) {
    }

    public function handle(string $uuid): ReprocessAssetResult
    {
        $result = $this->gateway->reprocess($uuid);

        return new ReprocessAssetResult($result['status'], $result['payload']);
    }
}
