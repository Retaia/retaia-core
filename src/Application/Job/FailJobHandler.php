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

    public function handle(string $jobId, string $lockToken, bool $retryable, string $errorCode, string $message): FailJobResult
    {
        $job = $this->gateway->fail($jobId, $lockToken, $retryable, $errorCode, $message);
        if ($job === null) {
            $code = $this->resolveLockConflictCodeHandler->handle($jobId, $lockToken);

            return new FailJobResult($code === 'STALE_LOCK_TOKEN'
                ? FailJobResult::STATUS_STALE_LOCK_TOKEN
                : FailJobResult::STATUS_LOCK_INVALID);
        }

        return new FailJobResult(FailJobResult::STATUS_FAILED, $job);
    }
}
