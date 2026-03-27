<?php

namespace App\Application\Job;

use App\Application\Job\Port\JobGateway;

final class FailJobHandler
{
    public function __construct(
        private JobGateway $gateway,
        private ResolveJobLockConflictCodeHandler $resolveLockConflictCodeHandler,
    ) {
    }

    public function handle(string $jobId, string $actorId, string $lockToken, int $fencingToken, bool $retryable, string $errorCode, string $message): FailJobResult
    {
        $job = $this->gateway->fail($jobId, $actorId, $lockToken, $fencingToken, $retryable, $errorCode, $message);
        if ($job === null) {
            $code = $this->resolveLockConflictCodeHandler->handle($jobId, $actorId, $lockToken, $fencingToken);

            return new FailJobResult($code === 'STALE_LOCK_TOKEN'
                ? FailJobResult::STATUS_STALE_LOCK_TOKEN
                : FailJobResult::STATUS_LOCK_INVALID);
        }

        return new FailJobResult(FailJobResult::STATUS_FAILED, $job);
    }
}
