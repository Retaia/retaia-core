<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\Port\PasswordResetGateway;
use App\Application\Auth\Port\PasswordResetRequestRateLimiterGateway;
use App\Application\Auth\RequestPasswordResetEndpointHandler;
use App\Application\Auth\RequestPasswordResetEndpointResult;
use App\Application\Auth\RequestPasswordResetHandler;
use PHPUnit\Framework\TestCase;

final class RequestPasswordResetEndpointHandlerTest extends TestCase
{
    public function testHandleReturnsValidationFailedWhenEmailMissing(): void
    {
        $gateway = $this->createMock(PasswordResetGateway::class);
        $gateway->expects(self::never())->method('requestReset');

        $limiter = $this->createMock(PasswordResetRequestRateLimiterGateway::class);
        $limiter->expects(self::never())->method('retryInSecondsOrNull');

        $handler = new RequestPasswordResetEndpointHandler(new RequestPasswordResetHandler($gateway), $limiter);
        $result = $handler->handle([], '10.0.0.1');

        self::assertSame(RequestPasswordResetEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testHandleReturnsTooManyAttemptsWhenRateLimited(): void
    {
        $gateway = $this->createMock(PasswordResetGateway::class);
        $gateway->expects(self::never())->method('requestReset');

        $limiter = $this->createMock(PasswordResetRequestRateLimiterGateway::class);
        $limiter->expects(self::once())->method('retryInSecondsOrNull')->with('admin@retaia.local', '10.0.0.2')->willReturn(42);

        $handler = new RequestPasswordResetEndpointHandler(new RequestPasswordResetHandler($gateway), $limiter);
        $result = $handler->handle(['email' => 'admin@retaia.local'], '10.0.0.2');

        self::assertSame(RequestPasswordResetEndpointResult::STATUS_TOO_MANY_ATTEMPTS, $result->status());
        self::assertSame(42, $result->retryInSeconds());
    }

    public function testHandleReturnsAcceptedAndTokenWhenRequestAccepted(): void
    {
        $gateway = $this->createMock(PasswordResetGateway::class);
        $gateway->expects(self::once())->method('requestReset')->with('admin@retaia.local')->willReturn('tok_123');

        $limiter = $this->createMock(PasswordResetRequestRateLimiterGateway::class);
        $limiter->expects(self::once())->method('retryInSecondsOrNull')->with('admin@retaia.local', '10.0.0.3')->willReturn(null);

        $handler = new RequestPasswordResetEndpointHandler(new RequestPasswordResetHandler($gateway), $limiter);
        $result = $handler->handle(['email' => 'admin@retaia.local'], '10.0.0.3');

        self::assertSame(RequestPasswordResetEndpointResult::STATUS_ACCEPTED, $result->status());
        self::assertSame('tok_123', $result->token());
    }
}
