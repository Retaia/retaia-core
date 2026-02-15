<?php

namespace App\Application\Job;

use App\Application\Job\Port\JobGateway;

final class ClaimJobHandler
{
    public function __construct(
        private JobGateway $gateway,
    ) {
    }

    public function handle(string $jobId, string $actorId): ClaimJobResult
    {
        $job = $this->gateway->claim($jobId, $actorId, 300);
        if ($job === null) {
            return new ClaimJobResult(ClaimJobResult::STATUS_STATE_CONFLICT);
        }

        return new ClaimJobResult(ClaimJobResult::STATUS_CLAIMED, $job);
    }
}
