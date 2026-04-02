<?php

namespace App\Api\Service;

final class AgentJobCurrentProjector
{
    public function __construct(
        private AgentJobProjectionQueryRunner $queryRunner,
        private AgentJobProjectionRowMapper $rowMapper,
    ) {
    }

    /**
     * @param array<int, string> $agentIds
     * @return array<string, array<string, string>>
     */
    public function project(array $agentIds): array
    {
        $rows = $this->queryRunner->fetchRowsForIds(
            'SELECT claimed_by AS agent_id, id, job_type, asset_uuid, claimed_at, locked_until
             FROM processing_job
             WHERE status = :status
               AND claimed_by IN (%s)
               AND claimed_at IS NOT NULL
               AND locked_until IS NOT NULL',
            'claimed_by',
            $agentIds,
            ['status' => 'claimed']
        );

        $snapshots = [];
        foreach ($rows as $row) {
            $agentId = $this->rowMapper->agentId($row);
            $candidate = $this->rowMapper->currentJobCandidate($row);
            if ($agentId === '' || $candidate['claimed_at'] === '' || $candidate['locked_until'] === '') {
                continue;
            }

            if (($snapshots[$agentId]['claimed_at'] ?? '') < $candidate['claimed_at']) {
                $snapshots[$agentId] = $candidate;
            }
        }

        return $snapshots;
    }

}
