<?php

namespace App\Application\Job;

use App\Application\Job\Port\JobGateway;
use App\Job\Job;
use App\Job\JobStatus;

final class ResolveJobLockConflictCodeHandler
{
    public function __construct(
        private JobGateway $gateway,
    ) {
    }

    public function handle(string $jobId, string $lockToken): string
    {
        $current = $this->gateway->find($jobId);
        if ($current instanceof Job
            && $current->status === JobStatus::CLAIMED
            && is_string($current->lockToken)
            && $current->lockToken !== ''
            && !hash_equals($current->lockToken, $lockToken)
        ) {
            return 'STALE_LOCK_TOKEN';
        }

        return 'LOCK_INVALID';
    }
}
