<?php

namespace App\Application\Workflow;

use App\Application\Workflow\Port\WorkflowGateway;

final class ApplyDecisionsHandler
{
    public function __construct(
        private WorkflowGateway $gateway,
    ) {
    }

    /**
     * @param array<int, string> $uuids
     */
    public function handle(string $action, array $uuids): ApplyDecisionsResult
    {
        if (trim($action) === '' || $uuids === []) {
            return new ApplyDecisionsResult(ApplyDecisionsResult::STATUS_VALIDATION_FAILED);
        }

        return new ApplyDecisionsResult(
            ApplyDecisionsResult::STATUS_APPLIED,
            $this->gateway->applyDecisions($uuids, $action)
        );
    }
}
