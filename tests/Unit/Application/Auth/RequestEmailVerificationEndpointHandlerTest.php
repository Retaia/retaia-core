<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\Port\EmailVerificationGateway;
use App\Application\Auth\Port\EmailVerificationRequestRateLimiterGateway;
use App\Application\Auth\RequestEmailVerificationEndpointHandler;
use App\Application\Auth\RequestEmailVerificationEndpointResult;
use App\Application\Auth\RequestEmailVerificationHandler;
use PHPUnit\Framework\TestCase;

final class RequestEmailVerificationEndpointHandlerTest extends TestCase
{
    public function testHandleReturnsValidationFailedWhenEmailMissing(): void
    {
        $gateway = $this->createMock(EmailVerificationGateway::class);
        $gateway->expects(self::never())->method('requestVerification');

        $limiter = $this->createMock(EmailVerificationRequestRateLimiterGateway::class);
        $limiter->expects(self::never())->method('retryInSecondsOrNull');

        $handler = new RequestEmailVerificationEndpointHandler(new RequestEmailVerificationHandler($gateway), $limiter);
        $result = $handler->handle([], '10.0.0.1');

        self::assertSame(RequestEmailVerificationEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testHandleReturnsTooManyAttemptsWhenRateLimited(): void
    {
        $gateway = $this->createMock(EmailVerificationGateway::class);
        $gateway->expects(self::never())->method('requestVerification');

        $limiter = $this->createMock(EmailVerificationRequestRateLimiterGateway::class);
        $limiter->expects(self::once())->method('retryInSecondsOrNull')->with('admin@retaia.local', '10.0.0.2')->willReturn(30);

        $handler = new RequestEmailVerificationEndpointHandler(new RequestEmailVerificationHandler($gateway), $limiter);
        $result = $handler->handle(['email' => 'admin@retaia.local'], '10.0.0.2');

        self::assertSame(RequestEmailVerificationEndpointResult::STATUS_TOO_MANY_ATTEMPTS, $result->status());
        self::assertSame(30, $result->retryInSeconds());
    }

    public function testHandleReturnsAcceptedAndTokenWhenRequestAccepted(): void
    {
        $gateway = $this->createMock(EmailVerificationGateway::class);
        $gateway->expects(self::once())->method('requestVerification')->with('admin@retaia.local')->willReturn('verif_123');

        $limiter = $this->createMock(EmailVerificationRequestRateLimiterGateway::class);
        $limiter->expects(self::once())->method('retryInSecondsOrNull')->with('admin@retaia.local', '10.0.0.3')->willReturn(null);

        $handler = new RequestEmailVerificationEndpointHandler(new RequestEmailVerificationHandler($gateway), $limiter);
        $result = $handler->handle(['email' => 'admin@retaia.local'], '10.0.0.3');

        self::assertSame(RequestEmailVerificationEndpointResult::STATUS_ACCEPTED, $result->status());
        self::assertSame('verif_123', $result->token());
    }
}
