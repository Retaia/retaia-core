<?php

namespace App\Application\Workflow;

use App\Application\Workflow\Port\WorkflowGateway;

final class PurgeAssetHandler
{
    public function __construct(
        private WorkflowGateway $gateway,
    ) {
    }

    public function handle(string $assetUuid): PurgeAssetResult
    {
        $result = $this->gateway->purge($assetUuid);

        return match ($result['status']) {
            PurgeAssetResult::STATUS_NOT_FOUND => new PurgeAssetResult(PurgeAssetResult::STATUS_NOT_FOUND),
            PurgeAssetResult::STATUS_STATE_CONFLICT => new PurgeAssetResult(PurgeAssetResult::STATUS_STATE_CONFLICT),
            default => new PurgeAssetResult(PurgeAssetResult::STATUS_PURGED, $result['asset'] ?? null),
        };
    }
}
