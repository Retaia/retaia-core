<?php

namespace App\Tests\Unit\Api\Service\AgentSignature;

use App\Api\Service\AgentSignature\AgentPublicKeyRecord;
use App\Api\Service\AgentSignature\AgentPublicKeyRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AgentPublicKeyRepositoryTest extends TestCase
{
    public function testSaveAndFindByAgentIdAndFingerprint(): void
    {
        $repository = new AgentPublicKeyRepository($this->connection());
        $repository->save(new AgentPublicKeyRecord(
            '11111111-1111-4111-8111-111111111111',
            'ABCD1234EF567890ABCD1234EF567890ABCD1234',
            'public-key',
            1710000000,
        ));

        $record = $repository->findByAgentIdAndFingerprint(
            '11111111-1111-4111-8111-111111111111',
            'ABCD1234EF567890ABCD1234EF567890ABCD1234'
        );

        self::assertNotNull($record);
        self::assertSame('public-key', $record->publicKey);
    }

    public function testSaveUpdatesExistingKeyForAgent(): void
    {
        $repository = new AgentPublicKeyRepository($this->connection());
        $repository->save(new AgentPublicKeyRecord(
            '11111111-1111-4111-8111-111111111111',
            'ABCD1234EF567890ABCD1234EF567890ABCD1234',
            'public-key-v1',
            1710000000,
        ));
        $repository->save(new AgentPublicKeyRecord(
            '11111111-1111-4111-8111-111111111111',
            'FFFF1234EF567890ABCD1234EF567890ABCD1234',
            'public-key-v2',
            1710000100,
        ));

        self::assertNull($repository->findByAgentIdAndFingerprint(
            '11111111-1111-4111-8111-111111111111',
            'ABCD1234EF567890ABCD1234EF567890ABCD1234'
        ));
        self::assertSame('public-key-v2', $repository->findByAgentId('11111111-1111-4111-8111-111111111111')?->publicKey);
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement('CREATE TABLE agent_public_key (agent_id VARCHAR(36) PRIMARY KEY NOT NULL, openpgp_fingerprint VARCHAR(40) NOT NULL, openpgp_public_key CLOB NOT NULL, updated_at INTEGER NOT NULL)');

        return $connection;
    }
}
