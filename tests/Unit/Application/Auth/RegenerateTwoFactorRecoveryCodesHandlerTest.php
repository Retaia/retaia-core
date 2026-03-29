<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\Port\TwoFactorGateway;
use App\Application\Auth\RegenerateTwoFactorRecoveryCodesHandler;
use App\Application\Auth\RegenerateTwoFactorRecoveryCodesResult;
use PHPUnit\Framework\TestCase;

final class RegenerateTwoFactorRecoveryCodesHandlerTest extends TestCase
{
    public function testHandleReturnsInvalidCodeWhenOtpVerificationFails(): void
    {
        $gateway = $this->createMock(TwoFactorGateway::class);
        $gateway->expects(self::once())->method('verifyOtp')->with('u-1', '000000')->willReturn(false);
        $gateway->expects(self::never())->method('regenerateRecoveryCodes');

        $handler = new RegenerateTwoFactorRecoveryCodesHandler($gateway);
        $result = $handler->handle('u-1', '000000');

        self::assertSame(RegenerateTwoFactorRecoveryCodesResult::STATUS_INVALID_CODE, $result->status());
    }
}
