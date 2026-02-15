<?php

namespace App\Application\Workflow;

final class CheckBulkDecisionsEnabledHandler
{
    public function __construct(
        private bool $bulkDecisionsEnabled,
    ) {
    }

    public function handle(): bool
    {
        return $this->bulkDecisionsEnabled;
    }
}
