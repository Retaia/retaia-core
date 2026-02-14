<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\EnableTwoFactorHandler;
use App\Application\Auth\EnableTwoFactorResult;
use App\Application\Auth\Port\TwoFactorGateway;
use PHPUnit\Framework\TestCase;

final class EnableTwoFactorHandlerTest extends TestCase
{
    public function testHandleReturnsEnabledWhenGatewayAcceptsCode(): void
    {
        $gateway = $this->createMock(TwoFactorGateway::class);
        $gateway->expects(self::once())->method('enable')->with('u-1', '123456')->willReturn(true);

        $handler = new EnableTwoFactorHandler($gateway);
        $result = $handler->handle('u-1', '123456');

        self::assertSame(EnableTwoFactorResult::STATUS_ENABLED, $result->status());
    }

    public function testHandleReturnsInvalidCodeWhenGatewayRejectsCode(): void
    {
        $gateway = $this->createMock(TwoFactorGateway::class);
        $gateway->expects(self::once())->method('enable')->with('u-1', '000000')->willReturn(false);

        $handler = new EnableTwoFactorHandler($gateway);
        $result = $handler->handle('u-1', '000000');

        self::assertSame(EnableTwoFactorResult::STATUS_INVALID_CODE, $result->status());
    }

    public function testHandleReturnsAlreadyEnabledWhenGatewayThrowsAlreadyEnabled(): void
    {
        $gateway = $this->createMock(TwoFactorGateway::class);
        $gateway->expects(self::once())->method('enable')->willThrowException(new \RuntimeException('MFA_ALREADY_ENABLED'));

        $handler = new EnableTwoFactorHandler($gateway);
        $result = $handler->handle('u-1', '123456');

        self::assertSame(EnableTwoFactorResult::STATUS_ALREADY_ENABLED, $result->status());
    }

    public function testHandleReturnsSetupRequiredWhenGatewayThrowsOtherRuntimeError(): void
    {
        $gateway = $this->createMock(TwoFactorGateway::class);
        $gateway->expects(self::once())->method('enable')->willThrowException(new \RuntimeException('MFA_SETUP_REQUIRED'));

        $handler = new EnableTwoFactorHandler($gateway);
        $result = $handler->handle('u-1', '123456');

        self::assertSame(EnableTwoFactorResult::STATUS_SETUP_REQUIRED, $result->status());
    }
}
