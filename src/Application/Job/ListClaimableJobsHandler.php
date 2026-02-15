<?php

namespace App\Application\Job;

use App\Application\Job\Port\JobGateway;

final class ListClaimableJobsHandler
{
    public function __construct(
        private JobGateway $gateway,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(int $limit): array
    {
        return array_map(static fn ($job): array => $job->toArray(), $this->gateway->listClaimable($limit));
    }
}
