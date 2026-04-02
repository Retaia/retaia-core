<?php

namespace App\Api\Service;

final class AgentJobProjectionAgentIdNormalizer
{
    /**
     * @param array<int, mixed> $agentIds
     * @return list<string>
     */
    public function normalize(array $agentIds): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $agentId): string => trim((string) $agentId),
            $agentIds
        ), static fn (string $agentId): bool => $agentId !== '')));
    }

    /**
     * @param array<int, string> $agentIds
     * @return array<string, array{current_job: null, last_successful_job: null, last_failed_job: null}>
     */
    public function emptySnapshots(array $agentIds): array
    {
        $snapshots = [];
        foreach ($agentIds as $agentId) {
            $snapshots[$agentId] = [
                'current_job' => null,
                'last_successful_job' => null,
                'last_failed_job' => null,
            ];
        }

        return $snapshots;
    }
}
