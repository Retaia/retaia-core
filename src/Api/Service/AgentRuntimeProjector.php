<?php

namespace App\Api\Service;

use Doctrine\DBAL\Connection;

final class AgentRuntimeProjector
{
    public function __construct(
        private Connection $connection,
        private AgentRuntimeRowMapper $rowMapper,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT agent_id, client_id, agent_name, agent_version, os_name, os_version, arch, effective_capabilities, capability_warnings, last_register_at, last_seen_at, last_heartbeat_at, max_parallel_jobs, feature_flags_contract_version, effective_feature_flags_contract_version, server_time_skew_seconds
             FROM agent_runtime'
        );

        return array_map(fn (array $row): array => $this->rowMapper->fromPersistenceRow($row), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOne(string $agentId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT agent_id, client_id, agent_name, agent_version, os_name, os_version, arch, effective_capabilities, capability_warnings, last_register_at, last_seen_at, last_heartbeat_at, max_parallel_jobs, feature_flags_contract_version, effective_feature_flags_contract_version, server_time_skew_seconds
             FROM agent_runtime
             WHERE agent_id = :agentId',
            ['agentId' => $agentId]
        );

        return is_array($row) ? $this->rowMapper->fromPersistenceRow($row) : null;
    }
}
