<?php

namespace App\Application\Asset;

use App\Application\Asset\Port\AssetWorkflowGateway;

final class ReopenAssetHandler
{
    public function __construct(
        private AssetWorkflowGateway $gateway,
    ) {
    }

    public function handle(string $uuid): ReopenAssetResult
    {
        $result = $this->gateway->reopen($uuid);

        return new ReopenAssetResult($result['status'], $result['payload']);
    }
}
