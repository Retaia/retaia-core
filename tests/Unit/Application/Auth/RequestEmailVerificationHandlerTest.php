<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\Port\EmailVerificationGateway;
use App\Application\Auth\RequestEmailVerificationHandler;
use App\Application\Auth\RequestEmailVerificationResult;
use PHPUnit\Framework\TestCase;

final class RequestEmailVerificationHandlerTest extends TestCase
{
    public function testHandleReturnsAcceptedWithTokenWhenGatewayReturnsToken(): void
    {
        $gateway = $this->createMock(EmailVerificationGateway::class);
        $gateway->expects(self::once())->method('requestVerification')->with('pending@retaia.local')->willReturn('signed-token');

        $handler = new RequestEmailVerificationHandler($gateway);
        $result = $handler->handle('pending@retaia.local');

        self::assertSame(RequestEmailVerificationResult::STATUS_ACCEPTED, $result->status());
        self::assertSame('signed-token', $result->token());
    }

    public function testHandleReturnsAcceptedWithoutTokenWhenGatewayReturnsNull(): void
    {
        $gateway = $this->createMock(EmailVerificationGateway::class);
        $gateway->expects(self::once())->method('requestVerification')->with('unknown@retaia.local')->willReturn(null);

        $handler = new RequestEmailVerificationHandler($gateway);
        $result = $handler->handle('unknown@retaia.local');

        self::assertSame(RequestEmailVerificationResult::STATUS_ACCEPTED, $result->status());
        self::assertNull($result->token());
    }
}
