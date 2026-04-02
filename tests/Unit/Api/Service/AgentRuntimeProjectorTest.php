<?php

namespace App\Tests\Unit\Api\Service;

use App\Api\Service\AgentRuntimeProjector;
use App\Api\Service\AgentRuntimeRowMapper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AgentRuntimeProjectorTest extends TestCase
{
    public function testFindOneAndFindAllProjectStoredRows(): void
    {
        $connection = $this->connection();
        $connection->insert('agent_runtime', [
            'agent_id' => 'agent-1',
            'client_id' => 'client-1',
            'agent_name' => 'ffmpeg',
            'agent_version' => '1.0.0',
            'os_name' => 'linux',
            'os_version' => '6.8',
            'arch' => 'x86_64',
            'effective_capabilities' => '["extract_facts"]',
            'capability_warnings' => '[]',
            'last_register_at' => '2026-04-02T10:00:00+00:00',
            'last_seen_at' => '2026-04-02T10:01:00+00:00',
            'last_heartbeat_at' => null,
            'max_parallel_jobs' => 2,
            'feature_flags_contract_version' => '1.0.0',
            'effective_feature_flags_contract_version' => '1.1.0',
            'server_time_skew_seconds' => 4,
        ]);

        $projector = new AgentRuntimeProjector($connection, new AgentRuntimeRowMapper());

        $entry = $projector->findOne('agent-1');
        $all = $projector->findAll();

        self::assertIsArray($entry);
        self::assertSame('client-1', $entry['client_id'] ?? null);
        self::assertSame(['extract_facts'], $entry['effective_capabilities'] ?? null);
        self::assertCount(1, $all);
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement("CREATE TABLE agent_runtime (agent_id VARCHAR(36) PRIMARY KEY NOT NULL, client_id VARCHAR(64) NOT NULL, agent_name VARCHAR(255) NOT NULL, agent_version VARCHAR(64) NOT NULL, os_name VARCHAR(32) DEFAULT NULL, os_version VARCHAR(64) DEFAULT NULL, arch VARCHAR(32) DEFAULT NULL, effective_capabilities CLOB NOT NULL, capability_warnings CLOB NOT NULL, last_register_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, last_heartbeat_at DATETIME DEFAULT NULL, max_parallel_jobs INTEGER NOT NULL, feature_flags_contract_version VARCHAR(32) DEFAULT NULL, effective_feature_flags_contract_version VARCHAR(32) DEFAULT NULL, server_time_skew_seconds INTEGER DEFAULT NULL)");

        return $connection;
    }
}
