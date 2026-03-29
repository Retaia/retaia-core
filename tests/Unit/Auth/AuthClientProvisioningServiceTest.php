<?php

namespace App\Tests\Unit\Auth;

use App\Auth\AuthClientRegistryRepository;
use App\Auth\AuthClientProvisioningService;
use App\Tests\Support\AuthClientRegistryEntityManagerTrait;
use PHPUnit\Framework\TestCase;

final class AuthClientProvisioningServiceTest extends TestCase
{
    use AuthClientRegistryEntityManagerTrait;

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
        return new AuthClientRegistryRepository($this->authClientRegistryEntityManager());
    }
}
