<?php

namespace App\Tests\Unit\Application\AuthClient;

use App\Application\AuthClient\MintClientTokenHandler;
use App\Application\AuthClient\MintClientTokenResult;
use App\Application\AuthClient\Port\AuthClientGateway;
use App\Domain\AuthClient\TechnicalClientTokenPolicy;
use PHPUnit\Framework\TestCase;

final class MintClientTokenHandlerTest extends TestCase
{
    public function testReturnsForbiddenActorForUiWeb(): void
    {
        $gateway = $this->createStub(AuthClientGateway::class);
        $handler = new MintClientTokenHandler(new TechnicalClientTokenPolicy(), $gateway);

        $result = $handler->handle('agent-default', 'UI_WEB', 'secret');

        self::assertSame(MintClientTokenResult::STATUS_FORBIDDEN_ACTOR, $result->status());
    }

    public function testReturnsForbiddenScopeForMcpWhenAppDisabled(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->expects(self::once())->method('isMcpDisabledByAppPolicy')->willReturn(true);

        $handler = new MintClientTokenHandler(new TechnicalClientTokenPolicy(), $gateway);
        $result = $handler->handle('mcp-default', 'MCP', 'secret');

        self::assertSame(MintClientTokenResult::STATUS_FORBIDDEN_SCOPE, $result->status());
    }

    public function testReturnsUnauthorizedWhenCredentialsAreInvalid(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->method('isMcpDisabledByAppPolicy')->willReturn(false);
        $gateway->method('mintToken')->willReturn(null);

        $handler = new MintClientTokenHandler(new TechnicalClientTokenPolicy(), $gateway);
        $result = $handler->handle('agent-default', 'AGENT', 'bad-secret');

        self::assertSame(MintClientTokenResult::STATUS_UNAUTHORIZED, $result->status());
    }

    public function testReturnsSuccessWithTokenPayload(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->method('isMcpDisabledByAppPolicy')->willReturn(false);
        $gateway->method('mintToken')->willReturn([
            'access_token' => 'header.payload.sig',
            'token_type' => 'Bearer',
            'client_id' => 'agent-default',
            'client_kind' => 'AGENT',
        ]);

        $handler = new MintClientTokenHandler(new TechnicalClientTokenPolicy(), $gateway);
        $result = $handler->handle('agent-default', 'AGENT', 'agent-secret');

        self::assertSame(MintClientTokenResult::STATUS_SUCCESS, $result->status());
        self::assertSame('Bearer', $result->token()['token_type'] ?? null);
    }
}
