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

    public function handle(string $jobId, string $actorId, string $lockToken, ?int $fencingToken = null): string
    {
        $current = $this->gateway->find($jobId);
        if ($current instanceof Job
            && $current->status === JobStatus::CLAIMED
            && is_string($current->lockToken)
            && $current->lockToken !== ''
        ) {
            if (!hash_equals($current->lockToken, $lockToken)) {
                return 'STALE_LOCK_TOKEN';
            }
            if ($current->claimedBy !== null && $current->claimedBy !== '' && !hash_equals($current->claimedBy, $actorId)) {
                return 'LOCK_INVALID';
            }
            if ($fencingToken !== null && $current->fencingToken !== null && $current->fencingToken !== $fencingToken) {
                return 'STALE_LOCK_TOKEN';
            }

            return 'STALE_LOCK_TOKEN';
        }

        return 'LOCK_INVALID';
    }
}
