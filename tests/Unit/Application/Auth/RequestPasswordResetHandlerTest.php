<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\Port\PasswordResetGateway;
use App\Application\Auth\RequestPasswordResetHandler;
use App\Application\Auth\RequestPasswordResetResult;
use PHPUnit\Framework\TestCase;

final class RequestPasswordResetHandlerTest extends TestCase
{
    public function testHandleReturnsAcceptedWithTokenWhenGatewayReturnsToken(): void
    {
        $gateway = $this->createMock(PasswordResetGateway::class);
        $gateway->expects(self::once())->method('requestReset')->with('admin@retaia.local')->willReturn('reset-token');

        $handler = new RequestPasswordResetHandler($gateway);
        $result = $handler->handle('admin@retaia.local');

        self::assertSame(RequestPasswordResetResult::STATUS_ACCEPTED, $result->status());
        self::assertSame('reset-token', $result->token());
    }

    public function testHandleReturnsAcceptedWithoutTokenWhenGatewayReturnsNull(): void
    {
        $gateway = $this->createMock(PasswordResetGateway::class);
        $gateway->expects(self::once())->method('requestReset')->with('unknown@retaia.local')->willReturn(null);

        $handler = new RequestPasswordResetHandler($gateway);
        $result = $handler->handle('unknown@retaia.local');

        self::assertSame(RequestPasswordResetResult::STATUS_ACCEPTED, $result->status());
        self::assertNull($result->token());
    }
}
