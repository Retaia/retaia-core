<?php

namespace App\Application\Job;

final class JobListEndpointHandler
{
    public function __construct(
        private ListClaimableJobsHandler $listClaimableJobsHandler,
        private JobEndpointActorContextResolver $actorContextResolver,
    ) {
    }

    public function handle(int $limit): JobEndpointResult
    {
        $actorRoles = $this->actorContextResolver->actorRoles();

        return new JobEndpointResult(
            JobEndpointResult::STATUS_SUCCESS,
            ['items' => $this->listClaimableJobsHandler->handle($limit, $actorRoles)],
            null,
            $this->actorContextResolver->actorId()
        );
    }
}
