<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\Port\PasswordPolicyGateway;
use App\Application\Auth\Port\PasswordResetGateway;
use App\Application\Auth\ResetPasswordEndpointHandler;
use App\Application\Auth\ResetPasswordEndpointResult;
use App\Application\Auth\ResetPasswordHandler;
use PHPUnit\Framework\TestCase;

final class ResetPasswordEndpointHandlerTest extends TestCase
{
    public function testHandleReturnsValidationFailedWhenPayloadMissingFields(): void
    {
        $policyGateway = $this->createMock(PasswordPolicyGateway::class);
        $policyGateway->expects(self::never())->method('violations');

        $resetGateway = $this->createMock(PasswordResetGateway::class);
        $resetGateway->expects(self::never())->method('resetPassword');

        $handler = new ResetPasswordEndpointHandler(new ResetPasswordHandler($policyGateway, $resetGateway));
        $result = $handler->handle(['token' => '']);

        self::assertSame(ResetPasswordEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
        self::assertSame([], $result->violations());
    }

    public function testHandleReturnsValidationFailedWithViolationsFromUseCase(): void
    {
        $policyGateway = $this->createMock(PasswordPolicyGateway::class);
        $policyGateway->expects(self::once())->method('violations')->with('weak')->willReturn(['password.too_weak']);

        $resetGateway = $this->createMock(PasswordResetGateway::class);
        $resetGateway->expects(self::never())->method('resetPassword');

        $handler = new ResetPasswordEndpointHandler(new ResetPasswordHandler($policyGateway, $resetGateway));
        $result = $handler->handle(['token' => 'token-1', 'new_password' => 'weak']);

        self::assertSame(ResetPasswordEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
        self::assertSame(['password.too_weak'], $result->violations());
    }

    public function testHandleReturnsInvalidTokenWhenUseCaseRejectsToken(): void
    {
        $policyGateway = $this->createMock(PasswordPolicyGateway::class);
        $policyGateway->expects(self::once())->method('violations')->with('StrongPass1!')->willReturn([]);

        $resetGateway = $this->createMock(PasswordResetGateway::class);
        $resetGateway->expects(self::once())->method('resetPassword')->with('token-2', 'StrongPass1!')->willReturn(false);

        $handler = new ResetPasswordEndpointHandler(new ResetPasswordHandler($policyGateway, $resetGateway));
        $result = $handler->handle(['token' => 'token-2', 'new_password' => 'StrongPass1!']);

        self::assertSame(ResetPasswordEndpointResult::STATUS_INVALID_TOKEN, $result->status());
    }

    public function testHandleReturnsPasswordResetWhenUseCaseSucceeds(): void
    {
        $policyGateway = $this->createMock(PasswordPolicyGateway::class);
        $policyGateway->expects(self::once())->method('violations')->with('StrongPass1!')->willReturn([]);

        $resetGateway = $this->createMock(PasswordResetGateway::class);
        $resetGateway->expects(self::once())->method('resetPassword')->with('token-3', 'StrongPass1!')->willReturn(true);

        $handler = new ResetPasswordEndpointHandler(new ResetPasswordHandler($policyGateway, $resetGateway));
        $result = $handler->handle(['token' => 'token-3', 'new_password' => 'StrongPass1!']);

        self::assertSame(ResetPasswordEndpointResult::STATUS_PASSWORD_RESET, $result->status());
    }
}
