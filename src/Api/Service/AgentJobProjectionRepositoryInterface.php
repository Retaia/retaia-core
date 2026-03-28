<?php

namespace App\Api\Service;

interface AgentJobProjectionRepositoryInterface
{
    /**
     * @param array<int, string> $agentIds
     * @return array<string, array{current_job:?array<string, mixed>, last_successful_job:?array<string, mixed>, last_failed_job:?array<string, mixed>}>
     */
    public function snapshotsForAgents(array $agentIds): array;
}
