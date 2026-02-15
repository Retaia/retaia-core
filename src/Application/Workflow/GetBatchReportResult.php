<?php

namespace App\Application\Workflow;

final class GetBatchReportResult
{
    public const STATUS_NOT_FOUND = 'NOT_FOUND';
    public const STATUS_FOUND = 'FOUND';

    /**
     * @param array<string, mixed>|null $report
     */
    public function __construct(
        private string $status,
        private ?array $report = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function report(): ?array
    {
        return $this->report;
    }
}
