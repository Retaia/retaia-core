<?php

namespace App\Application\Job;

use App\Application\Job\Port\JobGateway;

final class HeartbeatJobHandler
{
    public function __construct(
        private JobGateway $gateway,
        private ResolveJobLockConflictCodeHandler $resolveLockConflictCodeHandler,
    ) {
    }

    public function handle(string $jobId, string $lockToken): HeartbeatJobResult
    {
        $job = $this->gateway->heartbeat($jobId, $lockToken, 300);
        if ($job === null) {
            $code = $this->resolveLockConflictCodeHandler->handle($jobId, $lockToken);

            return new HeartbeatJobResult($code === 'STALE_LOCK_TOKEN'
                ? HeartbeatJobResult::STATUS_STALE_LOCK_TOKEN
                : HeartbeatJobResult::STATUS_LOCK_INVALID);
        }

        return new HeartbeatJobResult(HeartbeatJobResult::STATUS_HEARTBEATED, $job);
    }
}
