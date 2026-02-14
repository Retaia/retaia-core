<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\Port\PasswordPolicyGateway;
use App\Application\Auth\Port\PasswordResetGateway;
use App\Application\Auth\ResetPasswordHandler;
use App\Application\Auth\ResetPasswordResult;
use PHPUnit\Framework\TestCase;

final class ResetPasswordHandlerTest extends TestCase
{
    public function testHandleReturnsValidationFailedWhenPasswordViolatesPolicy(): void
    {
        $policyGateway = $this->createMock(PasswordPolicyGateway::class);
        $policyGateway->expects(self::once())->method('violations')->with('weak')->willReturn(['password.too_weak']);

        $resetGateway = $this->createMock(PasswordResetGateway::class);
        $resetGateway->expects(self::never())->method('resetPassword');

        $handler = new ResetPasswordHandler($policyGateway, $resetGateway);
        $result = $handler->handle('token-1', 'weak');

        self::assertSame(ResetPasswordResult::STATUS_VALIDATION_FAILED, $result->status());
        self::assertSame(['password.too_weak'], $result->violations());
    }

    public function testHandleReturnsInvalidTokenWhenGatewayRejectsReset(): void
    {
        $policyGateway = $this->createMock(PasswordPolicyGateway::class);
        $policyGateway->expects(self::once())->method('violations')->with('StrongPass1!')->willReturn([]);

        $resetGateway = $this->createMock(PasswordResetGateway::class);
        $resetGateway->expects(self::once())->method('resetPassword')->with('token-2', 'StrongPass1!')->willReturn(false);

        $handler = new ResetPasswordHandler($policyGateway, $resetGateway);
        $result = $handler->handle('token-2', 'StrongPass1!');

        self::assertSame(ResetPasswordResult::STATUS_INVALID_TOKEN, $result->status());
    }

    public function testHandleReturnsPasswordResetWhenGatewayAcceptsReset(): void
    {
        $policyGateway = $this->createMock(PasswordPolicyGateway::class);
        $policyGateway->expects(self::once())->method('violations')->with('StrongPass1!')->willReturn([]);

        $resetGateway = $this->createMock(PasswordResetGateway::class);
        $resetGateway->expects(self::once())->method('resetPassword')->with('token-3', 'StrongPass1!')->willReturn(true);

        $handler = new ResetPasswordHandler($policyGateway, $resetGateway);
        $result = $handler->handle('token-3', 'StrongPass1!');

        self::assertSame(ResetPasswordResult::STATUS_PASSWORD_RESET, $result->status());
    }
}
