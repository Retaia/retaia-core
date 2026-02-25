<?php

namespace App\Application\Job;

use App\Application\Job\Port\JobGateway;
use App\Job\Job;

final class SubmitJobHandler
{
    public function __construct(
        private JobGateway $gateway,
        private CheckSuggestTagsSubmitScopeHandler $checkSuggestTagsSubmitScopeHandler,
        private ResolveJobLockConflictCodeHandler $resolveLockConflictCodeHandler,
    ) {
    }

    /**
     * @param array<string, mixed> $result
     * @param array<int, string>   $actorRoles
     */
    public function handle(string $jobId, string $lockToken, string $jobType, array $result, array $actorRoles): SubmitJobResult
    {
        $current = $this->gateway->find($jobId);
        if ($current instanceof Job && $current->jobType !== $jobType) {
            return new SubmitJobResult(SubmitJobResult::STATUS_VALIDATION_FAILED);
        }

        if ($current instanceof Job
            && $current->jobType === 'suggest_tags'
            && !$this->checkSuggestTagsSubmitScopeHandler->handle($actorRoles)
        ) {
            return new SubmitJobResult(SubmitJobResult::STATUS_FORBIDDEN_SCOPE);
        }

        $job = $this->gateway->submit($jobId, $lockToken, $result);
        if ($job === null) {
            $code = $this->resolveLockConflictCodeHandler->handle($jobId, $lockToken);

            return new SubmitJobResult($code === 'STALE_LOCK_TOKEN'
                ? SubmitJobResult::STATUS_STALE_LOCK_TOKEN
                : SubmitJobResult::STATUS_LOCK_INVALID);
        }

        return new SubmitJobResult(SubmitJobResult::STATUS_SUBMITTED, $job);
    }
}
