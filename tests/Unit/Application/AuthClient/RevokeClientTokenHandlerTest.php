<?php

namespace App\Tests\Unit\Application\AuthClient;

use App\Application\AuthClient\Port\AuthClientGateway;
use App\Application\AuthClient\RevokeClientTokenHandler;
use App\Application\AuthClient\RevokeClientTokenResult;
use App\Domain\AuthClient\TechnicalClientAdminPolicy;
use PHPUnit\Framework\TestCase;

final class RevokeClientTokenHandlerTest extends TestCase
{
    public function testReturnsValidationFailedWhenClientIsUnknown(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->expects(self::once())->method('hasClient')->with('unknown')->willReturn(false);

        $handler = new RevokeClientTokenHandler(new TechnicalClientAdminPolicy(), $gateway);
        $result = $handler->handle('unknown');

        self::assertSame(RevokeClientTokenResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testReturnsForbiddenScopeForUiWebClient(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->method('hasClient')->willReturn(true);
        $gateway->method('clientKind')->willReturn('UI_WEB');

        $handler = new RevokeClientTokenHandler(new TechnicalClientAdminPolicy(), $gateway);
        $result = $handler->handle('rust-ui');

        self::assertSame(RevokeClientTokenResult::STATUS_FORBIDDEN_SCOPE, $result->status());
    }

    public function testReturnsSuccessForRevocableClient(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->method('hasClient')->willReturn(true);
        $gateway->method('clientKind')->willReturn('AGENT');
        $gateway->expects(self::once())->method('revokeToken')->with('agent-default')->willReturn(true);

        $handler = new RevokeClientTokenHandler(new TechnicalClientAdminPolicy(), $gateway);
        $result = $handler->handle('agent-default');

        self::assertSame(RevokeClientTokenResult::STATUS_SUCCESS, $result->status());
        self::assertSame('AGENT', $result->clientKind());
    }
}
