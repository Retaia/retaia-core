<?php

namespace App\Application\Job;

use App\Application\Job\Port\JobGateway;

final class ListClaimableJobsHandler
{
    public function __construct(
        private JobGateway $gateway,
        private JobContractPolicy $contractPolicy,
    ) {
    }

    /**
     * @param array<int, string> $actorRoles
     * @return array<int, array<string, mixed>>
     */
    public function handle(int $limit, array $actorRoles): array
    {
        $jobs = $this->gateway->listClaimable($limit);
        $jobs = array_filter(
            $jobs,
            fn ($job): bool => $this->contractPolicy->isV1JobType($job->jobType)
                && $this->contractPolicy->isActorCompatible($job->jobType, $actorRoles)
        );

        return array_map(static fn ($job): array => $job->toArray(), array_values($jobs));
    }
}
