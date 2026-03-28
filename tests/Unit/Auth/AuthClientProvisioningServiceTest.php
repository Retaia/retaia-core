<?php

namespace App\Tests\Unit\Auth;

use App\Auth\AuthClientRegistryRepository;
use App\Auth\AuthClientProvisioningService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AuthClientProvisioningServiceTest extends TestCase
{
    public function testProvisionClientReturnsNullForUnsupportedKind(): void
    {
        $service = $this->service();

        self::assertNull($service->provisionClient('UI_WEB'));
    }

    public function testProvisionClientCreatesAndPersistsAgentClient(): void
    {
        $repository = $this->repository();
        $service = new AuthClientProvisioningService($repository);

        $credentials = $service->provisionClient('AGENT');

        self::assertIsArray($credentials);
        self::assertSame('agent-', substr((string) $credentials['client_id'], 0, 6));
        self::assertSame(48, strlen((string) $credentials['secret_key']));

        $entry = $repository->findByClientId((string) $credentials['client_id']);
        self::assertNotNull($entry);
        self::assertSame('AGENT', $entry->clientKind);
        self::assertSame((string) $credentials['secret_key'], $entry->secretKey);
    }

    public function testProvisionClientCreatesAndPersistsMcpClient(): void
    {
        $repository = $this->repository();
        $service = new AuthClientProvisioningService($repository);

        $credentials = $service->provisionClient('MCP');

        self::assertIsArray($credentials);
        self::assertSame('mcp-', substr((string) $credentials['client_id'], 0, 4));
        self::assertSame(48, strlen((string) $credentials['secret_key']));

        $entry = $repository->findByClientId((string) $credentials['client_id']);
        self::assertNotNull($entry);
        self::assertSame('MCP', $entry->clientKind);
    }

    private function service(): AuthClientProvisioningService
    {
        return new AuthClientProvisioningService($this->repository());
    }

    private function repository(): AuthClientRegistryRepository
    {
        return new AuthClientRegistryRepository($this->connection());
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE auth_client_registry (client_id VARCHAR(64) PRIMARY KEY NOT NULL, client_kind VARCHAR(32) NOT NULL, secret_key VARCHAR(128) DEFAULT NULL, client_label VARCHAR(255) DEFAULT NULL, openpgp_public_key CLOB DEFAULT NULL, openpgp_fingerprint VARCHAR(40) DEFAULT NULL, registered_at VARCHAR(32) DEFAULT NULL, rotated_at VARCHAR(32) DEFAULT NULL)');

        return $connection;
    }
}
