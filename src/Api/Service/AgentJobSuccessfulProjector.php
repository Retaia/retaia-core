<?php

namespace App\Api\Service;

final class AgentJobSuccessfulProjector
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
            'SELECT completed_by AS agent_id, id, job_type, asset_uuid, completed_at
             FROM processing_job
             WHERE status = :status
               AND completed_by IN (%s)
               AND completed_at IS NOT NULL',
            'completed_by',
            $agentIds,
            ['status' => 'completed']
        );

        $snapshots = [];
        foreach ($rows as $row) {
            $agentId = $this->rowMapper->agentId($row);
            $candidate = $this->rowMapper->successfulJobCandidate($row);
            if ($agentId === '' || $candidate['completed_at'] === '') {
                continue;
            }

            if (($snapshots[$agentId]['completed_at'] ?? '') < $candidate['completed_at']) {
                $snapshots[$agentId] = $candidate;
            }
        }

        return $snapshots;
    }

}
