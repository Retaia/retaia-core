<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\AdminConfirmEmailVerificationHandler;
use App\Application\Auth\AdminConfirmEmailVerificationResult;
use App\Application\Auth\Port\EmailVerificationGateway;
use PHPUnit\Framework\TestCase;

final class AdminConfirmEmailVerificationHandlerTest extends TestCase
{
    public function testHandleReturnsVerifiedWhenUserExists(): void
    {
        $gateway = $this->createMock(EmailVerificationGateway::class);
        $gateway->expects(self::once())->method('forceVerifyByEmail')->with('pending@retaia.local', 'admin-1')->willReturn(true);

        $handler = new AdminConfirmEmailVerificationHandler($gateway);
        $result = $handler->handle('pending@retaia.local', 'admin-1');

        self::assertSame(AdminConfirmEmailVerificationResult::STATUS_VERIFIED, $result->status());
    }

    public function testHandleReturnsUserNotFoundWhenGatewayFails(): void
    {
        $gateway = $this->createMock(EmailVerificationGateway::class);
        $gateway->expects(self::once())->method('forceVerifyByEmail')->with('missing@retaia.local', null)->willReturn(false);

        $handler = new AdminConfirmEmailVerificationHandler($gateway);
        $result = $handler->handle('missing@retaia.local', null);

        self::assertSame(AdminConfirmEmailVerificationResult::STATUS_USER_NOT_FOUND, $result->status());
    }
}
