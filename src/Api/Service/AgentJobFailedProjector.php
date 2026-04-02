<?php

namespace App\Api\Service;

final class AgentJobFailedProjector
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
            'SELECT failed_by AS agent_id, id, job_type, asset_uuid, failed_at, result_payload
             FROM processing_job
             WHERE status = :status
               AND failed_by IN (%s)
               AND failed_at IS NOT NULL',
            'failed_by',
            $agentIds,
            ['status' => 'failed']
        );

        $snapshots = [];
        foreach ($rows as $row) {
            $agentId = $this->rowMapper->agentId($row);
            $candidate = $this->rowMapper->failedJobCandidate($row);
            if ($agentId === '' || $candidate['failed_at'] === '' || $candidate['error_code'] === '') {
                continue;
            }

            if (($snapshots[$agentId]['failed_at'] ?? '') < $candidate['failed_at']) {
                $snapshots[$agentId] = $candidate;
            }
        }

        return $snapshots;
    }

}
