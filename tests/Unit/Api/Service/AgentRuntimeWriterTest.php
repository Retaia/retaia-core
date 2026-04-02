<?php

namespace App\Tests\Unit\Api\Service;

use App\Api\Service\AgentRuntimeProjector;
use App\Api\Service\AgentRuntimeRowMapper;
use App\Api\Service\AgentRuntimeWriter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AgentRuntimeWriterTest extends TestCase
{
    public function testSaveRegistrationUpsertsAndTouchHeartbeatUpdatesTimestamp(): void
    {
        $connection = $this->connection();
        $mapper = new AgentRuntimeRowMapper();
        $projector = new AgentRuntimeProjector($connection, $mapper);
        $writer = new AgentRuntimeWriter($connection, $projector, $mapper);

        $writer->saveRegistration([
            'agent_id' => 'agent-1',
            'client_id' => 'client-1',
            'agent_name' => 'ffmpeg',
            'agent_version' => '1.0.0',
            'effective_capabilities' => ['extract_facts'],
            'capability_warnings' => [],
            'max_parallel_jobs' => 2,
        ]);

        $writer->saveRegistration([
            'agent_id' => 'agent-1',
            'agent_version' => '1.1.0',
            'effective_capabilities' => ['extract_facts', 'generate_preview'],
        ]);
        $writer->touchHeartbeat('agent-1');

        $entry = $projector->findOne('agent-1');

        self::assertIsArray($entry);
        self::assertSame('client-1', $entry['client_id'] ?? null);
        self::assertSame('1.1.0', $entry['agent_version'] ?? null);
        self::assertSame(['extract_facts', 'generate_preview'], $entry['effective_capabilities'] ?? null);
        self::assertNotNull($entry['last_heartbeat_at'] ?? null);
    }

    public function testTouchSeenIgnoresUnknownAgentIds(): void
    {
        $connection = $this->connection();
        $mapper = new AgentRuntimeRowMapper();
        $projector = new AgentRuntimeProjector($connection, $mapper);
        $writer = new AgentRuntimeWriter($connection, $projector, $mapper);

        $writer->touchSeen('unknown');

        self::assertNull($projector->findOne('unknown'));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement("CREATE TABLE agent_runtime (agent_id VARCHAR(36) PRIMARY KEY NOT NULL, client_id VARCHAR(64) NOT NULL, agent_name VARCHAR(255) NOT NULL, agent_version VARCHAR(64) NOT NULL, os_name VARCHAR(32) DEFAULT NULL, os_version VARCHAR(64) DEFAULT NULL, arch VARCHAR(32) DEFAULT NULL, effective_capabilities CLOB NOT NULL, capability_warnings CLOB NOT NULL, last_register_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, last_heartbeat_at DATETIME DEFAULT NULL, max_parallel_jobs INTEGER NOT NULL, feature_flags_contract_version VARCHAR(32) DEFAULT NULL, effective_feature_flags_contract_version VARCHAR(32) DEFAULT NULL, server_time_skew_seconds INTEGER DEFAULT NULL)");

        return $connection;
    }
}
