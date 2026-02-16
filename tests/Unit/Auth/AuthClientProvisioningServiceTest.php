<?php

namespace App\Tests\Unit\Auth;

use App\Auth\AuthClientProvisioningService;
use App\Auth\AuthClientStateStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class AuthClientProvisioningServiceTest extends TestCase
{
    public function testProvisionClientReturnsNullForUnsupportedKind(): void
    {
        $service = $this->service();

        self::assertNull($service->provisionClient('UI_WEB'));
    }

    public function testProvisionClientCreatesAndPersistsAgentClient(): void
    {
        $stateStore = $this->stateStore();
        $service = new AuthClientProvisioningService($stateStore);

        $credentials = $service->provisionClient('AGENT');

        self::assertIsArray($credentials);
        self::assertSame('agent-', substr((string) $credentials['client_id'], 0, 6));
        self::assertSame(48, strlen((string) $credentials['secret_key']));

        $registry = $stateStore->registry();
        self::assertArrayHasKey((string) $credentials['client_id'], $registry);
        self::assertSame('AGENT', $registry[(string) $credentials['client_id']]['client_kind'] ?? null);
        self::assertSame((string) $credentials['secret_key'], $registry[(string) $credentials['client_id']]['secret_key'] ?? null);
    }

    public function testProvisionClientCreatesAndPersistsMcpClient(): void
    {
        $stateStore = $this->stateStore();
        $service = new AuthClientProvisioningService($stateStore);

        $credentials = $service->provisionClient('MCP');

        self::assertIsArray($credentials);
        self::assertSame('mcp-', substr((string) $credentials['client_id'], 0, 4));
        self::assertSame(48, strlen((string) $credentials['secret_key']));

        $registry = $stateStore->registry();
        self::assertArrayHasKey((string) $credentials['client_id'], $registry);
        self::assertSame('MCP', $registry[(string) $credentials['client_id']]['client_kind'] ?? null);
    }

    private function service(): AuthClientProvisioningService
    {
        return new AuthClientProvisioningService($this->stateStore());
    }

    private function stateStore(): AuthClientStateStore
    {
        return new AuthClientStateStore(new ArrayAdapter());
    }
}
