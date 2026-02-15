<?php

namespace App\Application\Workflow;

use App\Application\Workflow\Port\WorkflowGateway;

final class PreviewPurgeHandler
{
    public function __construct(
        private WorkflowGateway $gateway,
    ) {
    }

    public function handle(string $assetUuid): PreviewPurgeResult
    {
        $preview = $this->gateway->previewPurge($assetUuid);
        if ($preview === null) {
            return new PreviewPurgeResult(PreviewPurgeResult::STATUS_NOT_FOUND);
        }

        return new PreviewPurgeResult(PreviewPurgeResult::STATUS_PREVIEWED, $preview);
    }
}
