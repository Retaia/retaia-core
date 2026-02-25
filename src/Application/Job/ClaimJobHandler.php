<?php

namespace App\Application\Job;

use App\Application\Job\Port\JobGateway;

final class ClaimJobHandler
{
    public function __construct(
        private JobGateway $gateway,
        private JobContractPolicy $contractPolicy,
    ) {
    }

    /**
     * @param array<int, string> $actorRoles
     */
    public function handle(string $jobId, string $actorId, array $actorRoles): ClaimJobResult
    {
        $current = $this->gateway->find($jobId);
        if ($current === null
            || !$this->contractPolicy->isV1JobType($current->jobType)
            || !$this->contractPolicy->isActorCompatible($current->jobType, $actorRoles)
        ) {
            return new ClaimJobResult(ClaimJobResult::STATUS_STATE_CONFLICT);
        }

        $job = $this->gateway->claim($jobId, $actorId, 300);
        if ($job === null) {
            return new ClaimJobResult(ClaimJobResult::STATUS_STATE_CONFLICT);
        }

        return new ClaimJobResult(ClaimJobResult::STATUS_CLAIMED, $job);
    }
}
