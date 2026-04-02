<?php

namespace App\Application\Job;

use App\Application\Job\Port\JobGateway;
use App\Job\Job;

final class SubmitJobHandler
{
    public function __construct(
        private JobGateway $gateway,
        private SubmitJobAssetMutator $assetMutator,
        private SubmitJobResultValidator $resultValidator,
        private CheckSuggestTagsSubmitScopeHandler $checkSuggestTagsSubmitScopeHandler,
        private ResolveJobLockConflictCodeHandler $resolveLockConflictCodeHandler,
    ) {
    }

    /**
     * @param array<string, mixed> $result
     * @param array<int, string>   $actorRoles
     */
    public function handle(string $jobId, string $actorId, string $lockToken, int $fencingToken, string $jobType, array $result, array $actorRoles): SubmitJobResult
    {
        $current = $this->gateway->find($jobId);
        if ($current instanceof Job && $current->jobType !== $jobType) {
            return new SubmitJobResult(SubmitJobResult::STATUS_VALIDATION_FAILED);
        }
        if (!$this->resultValidator->isAllowedForJobType($jobType, $result)) {
            return new SubmitJobResult(SubmitJobResult::STATUS_VALIDATION_FAILED);
        }

        if ($current instanceof Job
            && $current->jobType === 'suggest_tags'
            && !$this->checkSuggestTagsSubmitScopeHandler->handle($actorRoles)
        ) {
            return new SubmitJobResult(SubmitJobResult::STATUS_FORBIDDEN_SCOPE);
        }

        $job = $this->gateway->submit($jobId, $actorId, $lockToken, $fencingToken, $result);
        if ($job === null) {
            $code = $this->resolveLockConflictCodeHandler->handle($jobId, $actorId, $lockToken, $fencingToken);

            return new SubmitJobResult($code === 'STALE_LOCK_TOKEN'
                ? SubmitJobResult::STATUS_STALE_LOCK_TOKEN
                : SubmitJobResult::STATUS_LOCK_INVALID);
        }

        $this->assetMutator->apply($job, $result);

        return new SubmitJobResult(SubmitJobResult::STATUS_SUBMITTED, $job);
    }
}
