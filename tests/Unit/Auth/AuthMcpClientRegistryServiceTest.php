<?php

namespace App\Tests\Unit\Auth;

use App\Auth\AuthClientRegistryEntry;
use App\Auth\AuthClientRegistryRepositoryInterface;
use App\Auth\AuthMcpClientRegistryService;
use PHPUnit\Framework\TestCase;

final class AuthMcpClientRegistryServiceTest extends TestCase
{
    public function testRotateKeyRejectsFingerprintCollision(): void
    {
        $repository = $this->createMock(AuthClientRegistryRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findByClientId')
            ->with('mcp-1')
            ->willReturn(new AuthClientRegistryEntry('mcp-1', 'MCP', null, 'label', 'pub-1', 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', '2026-03-29T00:00:00+00:00', null));
        $repository->expects(self::once())
            ->method('findAll')
            ->willReturn([
                new AuthClientRegistryEntry('mcp-1', 'MCP', null, 'label', 'pub-1', 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', '2026-03-29T00:00:00+00:00', null),
                new AuthClientRegistryEntry('mcp-2', 'MCP', null, 'label', 'pub-2', 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB', '2026-03-29T00:00:00+00:00', null),
            ]);
        $repository->expects(self::never())->method('save');

        $service = new AuthMcpClientRegistryService($repository);
        $result = $service->rotateKey('mcp-1', 'pub-3', 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB', 'label');

        self::assertSame('STATE_CONFLICT', $result['status'] ?? null);
    }
}
