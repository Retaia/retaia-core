<?php

namespace App\Application\Job;

use App\Job\Job;

final class JobClaimEndpointHandler
{
    public function __construct(
        private ClaimJobHandler $claimJobHandler,
        private JobEndpointActorContextResolver $actorContextResolver,
    ) {
    }

    public function handle(string $jobId): JobEndpointResult
    {
        $actorId = $this->actorContextResolver->actorId();
        $result = $this->claimJobHandler->handle($jobId, $actorId, $this->actorContextResolver->actorRoles());
        if ($result->status() === ClaimJobResult::STATUS_STATE_CONFLICT || !$result->job() instanceof Job) {
            return new JobEndpointResult(JobEndpointResult::STATUS_STATE_CONFLICT, null, null, $actorId);
        }

        return new JobEndpointResult(JobEndpointResult::STATUS_SUCCESS, null, $result->job(), $actorId);
    }
}
