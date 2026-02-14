<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\DisableTwoFactorHandler;
use App\Application\Auth\DisableTwoFactorResult;
use App\Application\Auth\Port\TwoFactorGateway;
use PHPUnit\Framework\TestCase;

final class DisableTwoFactorHandlerTest extends TestCase
{
    public function testHandleReturnsDisabledWhenGatewayAcceptsCode(): void
    {
        $gateway = $this->createMock(TwoFactorGateway::class);
        $gateway->expects(self::once())->method('disable')->with('u-1', '123456')->willReturn(true);

        $handler = new DisableTwoFactorHandler($gateway);
        $result = $handler->handle('u-1', '123456');

        self::assertSame(DisableTwoFactorResult::STATUS_DISABLED, $result->status());
    }

    public function testHandleReturnsInvalidCodeWhenGatewayRejectsCode(): void
    {
        $gateway = $this->createMock(TwoFactorGateway::class);
        $gateway->expects(self::once())->method('disable')->with('u-1', '000000')->willReturn(false);

        $handler = new DisableTwoFactorHandler($gateway);
        $result = $handler->handle('u-1', '000000');

        self::assertSame(DisableTwoFactorResult::STATUS_INVALID_CODE, $result->status());
    }

    public function testHandleReturnsNotEnabledWhenGatewayThrows(): void
    {
        $gateway = $this->createMock(TwoFactorGateway::class);
        $gateway->expects(self::once())->method('disable')->willThrowException(new \RuntimeException('MFA_NOT_ENABLED'));

        $handler = new DisableTwoFactorHandler($gateway);
        $result = $handler->handle('u-1', '123456');

        self::assertSame(DisableTwoFactorResult::STATUS_NOT_ENABLED, $result->status());
    }
}
