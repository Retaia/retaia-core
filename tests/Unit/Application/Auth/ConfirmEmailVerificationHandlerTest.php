<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\ConfirmEmailVerificationHandler;
use App\Application\Auth\ConfirmEmailVerificationResult;
use App\Application\Auth\Port\EmailVerificationGateway;
use PHPUnit\Framework\TestCase;

final class ConfirmEmailVerificationHandlerTest extends TestCase
{
    public function testHandleReturnsVerifiedWhenGatewayAcceptsToken(): void
    {
        $gateway = $this->createMock(EmailVerificationGateway::class);
        $gateway->expects(self::once())->method('confirmVerification')->with('valid-token')->willReturn(true);

        $handler = new ConfirmEmailVerificationHandler($gateway);
        $result = $handler->handle('valid-token');

        self::assertSame(ConfirmEmailVerificationResult::STATUS_VERIFIED, $result->status());
    }

    public function testHandleReturnsInvalidTokenWhenGatewayRejectsToken(): void
    {
        $gateway = $this->createMock(EmailVerificationGateway::class);
        $gateway->expects(self::once())->method('confirmVerification')->with('bad-token')->willReturn(false);

        $handler = new ConfirmEmailVerificationHandler($gateway);
        $result = $handler->handle('bad-token');

        self::assertSame(ConfirmEmailVerificationResult::STATUS_INVALID_TOKEN, $result->status());
    }
}
