<?php

namespace App\Application\Workflow;

use App\Application\Workflow\Port\WorkflowGateway;

final class PreviewDecisionsHandler
{
    public function __construct(
        private WorkflowGateway $gateway,
    ) {
    }

    /**
     * @param array<int, string> $uuids
     */
    public function handle(string $action, array $uuids): PreviewDecisionsResult
    {
        if (trim($action) === '' || $uuids === []) {
            return new PreviewDecisionsResult(PreviewDecisionsResult::STATUS_VALIDATION_FAILED);
        }

        return new PreviewDecisionsResult(
            PreviewDecisionsResult::STATUS_PREVIEWED,
            $this->gateway->previewDecisions($uuids, $action)
        );
    }
}
