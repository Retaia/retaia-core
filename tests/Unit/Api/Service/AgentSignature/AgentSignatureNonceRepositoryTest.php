<?php

namespace App\Tests\Unit\Api\Service\AgentSignature;

use App\Api\Service\AgentSignature\AgentSignatureNonceRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AgentSignatureNonceRepositoryTest extends TestCase
{
    public function testConsumeRejectsReplayNonce(): void
    {
        $repository = new AgentSignatureNonceRepository($this->connection());

        self::assertTrue($repository->consume('agent-1', 'nonce-1', 300));
        self::assertFalse($repository->consume('agent-1', 'nonce-1', 300));
    }

    public function testConsumeAllowsSameNonceForDifferentAgents(): void
    {
        $repository = new AgentSignatureNonceRepository($this->connection());

        self::assertTrue($repository->consume('agent-1', 'nonce-1', 300));
        self::assertTrue($repository->consume('agent-2', 'nonce-1', 300));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement('CREATE TABLE agent_signature_nonce (nonce_key VARCHAR(64) PRIMARY KEY NOT NULL, agent_id VARCHAR(36) NOT NULL, expires_at INTEGER NOT NULL, consumed_at INTEGER NOT NULL)');
        $connection->executeStatement('CREATE INDEX idx_agent_signature_nonce_expires_at ON agent_signature_nonce (expires_at)');

        return $connection;
    }
}
