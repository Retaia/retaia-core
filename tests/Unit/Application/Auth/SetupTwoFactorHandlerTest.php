<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\Port\TwoFactorGateway;
use App\Application\Auth\SetupTwoFactorHandler;
use App\Application\Auth\SetupTwoFactorResult;
use PHPUnit\Framework\TestCase;

final class SetupTwoFactorHandlerTest extends TestCase
{
    public function testHandleReturnsReadyWithProvisioningPayload(): void
    {
        $gateway = $this->createMock(TwoFactorGateway::class);
        $gateway->expects(self::once())->method('setup')->with('u-1', 'user@retaia.local')->willReturn([
            'secret' => 'ABC123',
            'otpauth_uri' => 'otpauth://totp/Retaia:user@retaia.local?secret=ABC123',
        ]);

        $handler = new SetupTwoFactorHandler($gateway);
        $result = $handler->handle('u-1', 'user@retaia.local');

        self::assertSame(SetupTwoFactorResult::STATUS_READY, $result->status());
        self::assertSame('ABC123', $result->setup()['secret'] ?? null);
    }

    public function testHandleReturnsAlreadyEnabledWhenGatewayThrows(): void
    {
        $gateway = $this->createMock(TwoFactorGateway::class);
        $gateway->expects(self::once())->method('setup')->willThrowException(new \RuntimeException('MFA_ALREADY_ENABLED'));

        $handler = new SetupTwoFactorHandler($gateway);
        $result = $handler->handle('u-2', 'user2@retaia.local');

        self::assertSame(SetupTwoFactorResult::STATUS_ALREADY_ENABLED, $result->status());
        self::assertNull($result->setup());
    }
}
