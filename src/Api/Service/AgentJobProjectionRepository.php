<?php

namespace App\Api\Service;

use Doctrine\DBAL\Connection;

final class AgentJobProjectionRepository implements AgentJobProjectionRepositoryInterface
{
    private AgentJobProjectionAgentIdNormalizer $agentIdNormalizer;
    private AgentJobCurrentProjector $currentProjector;
    private AgentJobSuccessfulProjector $successfulProjector;
    private AgentJobFailedProjector $failedProjector;

    public function __construct(
        private Connection $connection,
    ) {
        $rowMapper = new AgentJobProjectionRowMapper();
        $queryRunner = new AgentJobProjectionQueryRunner($this->connection);
        $this->agentIdNormalizer = new AgentJobProjectionAgentIdNormalizer();
        $this->currentProjector = new AgentJobCurrentProjector($queryRunner, $rowMapper);
        $this->successfulProjector = new AgentJobSuccessfulProjector($queryRunner, $rowMapper);
        $this->failedProjector = new AgentJobFailedProjector($queryRunner, $rowMapper);
    }

    public function snapshotsForAgents(array $agentIds): array
    {
        $normalizedAgentIds = $this->agentIdNormalizer->normalize($agentIds);
        $snapshots = $this->agentIdNormalizer->emptySnapshots($normalizedAgentIds);

        if ($normalizedAgentIds === []) {
            return $snapshots;
        }

        foreach ($this->currentProjector->project($normalizedAgentIds) as $agentId => $job) {
            $snapshots[$agentId]['current_job'] = $job;
        }
        foreach ($this->successfulProjector->project($normalizedAgentIds) as $agentId => $job) {
            $snapshots[$agentId]['last_successful_job'] = $job;
        }
        foreach ($this->failedProjector->project($normalizedAgentIds) as $agentId => $job) {
            $snapshots[$agentId]['last_failed_job'] = $job;
        }

        return $snapshots;
    }
}
