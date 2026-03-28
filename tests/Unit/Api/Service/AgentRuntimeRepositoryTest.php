<?php

namespace App\Tests\Unit\Api\Service;

use App\Api\Service\AgentRuntimeRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AgentRuntimeRepositoryTest extends TestCase
{
    public function testSaveRegistrationAndFindAll(): void
    {
        $repository = new AgentRuntimeRepository($this->connection());

        $repository->saveRegistration([
            'agent_id' => '11111111-1111-4111-8111-111111111111',
            'client_id' => 'client-1',
            'agent_name' => 'ffmpeg',
            'agent_version' => '1.0.0',
            'os_name' => 'linux',
            'os_version' => '6.8',
            'arch' => 'x86_64',
            'effective_capabilities' => ['extract_facts'],
            'capability_warnings' => [],
            'max_parallel_jobs' => 2,
            'feature_flags_contract_version' => '1.0.0',
            'effective_feature_flags_contract_version' => '1.0.0',
        ]);

        $items = $repository->findAll();

        self::assertCount(1, $items);
        self::assertSame('client-1', $items[0]['client_id'] ?? null);
        self::assertSame(['extract_facts'], $items[0]['effective_capabilities'] ?? null);
    }

    public function testTouchHeartbeatUpdatesHeartbeatTimestamp(): void
    {
        $repository = new AgentRuntimeRepository($this->connection());
        $agentId = '11111111-1111-4111-8111-111111111111';

        $repository->saveRegistration([
            'agent_id' => $agentId,
            'client_id' => 'client-1',
            'agent_name' => 'ffmpeg',
            'agent_version' => '1.0.0',
            'effective_capabilities' => [],
            'capability_warnings' => [],
        ]);

        $repository->touchHeartbeat($agentId);
        $items = $repository->findAll();

        self::assertCount(1, $items);
        self::assertNotNull($items[0]['last_heartbeat_at'] ?? null);
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement("CREATE TABLE agent_runtime (agent_id VARCHAR(36) PRIMARY KEY NOT NULL, client_id VARCHAR(64) NOT NULL, agent_name VARCHAR(255) NOT NULL, agent_version VARCHAR(64) NOT NULL, os_name VARCHAR(32) DEFAULT NULL, os_version VARCHAR(64) DEFAULT NULL, arch VARCHAR(32) DEFAULT NULL, effective_capabilities CLOB NOT NULL, capability_warnings CLOB NOT NULL, last_register_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, last_heartbeat_at DATETIME DEFAULT NULL, max_parallel_jobs INTEGER NOT NULL, feature_flags_contract_version VARCHAR(32) DEFAULT NULL, effective_feature_flags_contract_version VARCHAR(32) DEFAULT NULL, server_time_skew_seconds INTEGER DEFAULT NULL)");

        return $connection;
    }
}
