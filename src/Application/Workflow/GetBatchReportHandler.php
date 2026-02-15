<?php

namespace App\Application\Workflow;

use App\Application\Workflow\Port\WorkflowGateway;

final class GetBatchReportHandler
{
    public function __construct(
        private WorkflowGateway $gateway,
    ) {
    }

    public function handle(string $batchId): GetBatchReportResult
    {
        $report = $this->gateway->getBatchReport($batchId);
        if ($report === null) {
            return new GetBatchReportResult(GetBatchReportResult::STATUS_NOT_FOUND);
        }

        return new GetBatchReportResult(GetBatchReportResult::STATUS_FOUND, $report);
    }
}
