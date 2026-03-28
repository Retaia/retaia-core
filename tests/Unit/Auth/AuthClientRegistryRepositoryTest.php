<?php

namespace App\Tests\Unit\Auth;

use App\Auth\AuthClientRegistryEntry;
use App\Auth\AuthClientRegistryRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AuthClientRegistryRepositoryTest extends TestCase
{
    public function testDefaultsAreSeededAndCustomEntryCanBeSaved(): void
    {
        $repository = new AuthClientRegistryRepository($this->connection());

        $default = $repository->findByClientId('agent-default');
        self::assertNotNull($default);
        self::assertSame('AGENT', $default->clientKind);
        self::assertSame('agent-secret', $default->secretKey);

        $repository->save(new AuthClientRegistryEntry(
            'agent-123',
            'AGENT',
            'secret-123',
            'worker',
            null,
            null,
            null,
            null,
        ));

        $stored = $repository->findByClientId('agent-123');
        self::assertNotNull($stored);
        self::assertSame('worker', $stored->clientLabel);
        self::assertSame('secret-123', $stored->secretKey);
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE auth_client_registry (client_id VARCHAR(64) PRIMARY KEY NOT NULL, client_kind VARCHAR(32) NOT NULL, secret_key VARCHAR(128) DEFAULT NULL, client_label VARCHAR(255) DEFAULT NULL, openpgp_public_key CLOB DEFAULT NULL, openpgp_fingerprint VARCHAR(40) DEFAULT NULL, registered_at VARCHAR(32) DEFAULT NULL, rotated_at VARCHAR(32) DEFAULT NULL)');

        return $connection;
    }
}
