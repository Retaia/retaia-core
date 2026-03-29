<?php

namespace App\Tests\Unit\User;

use App\User\Service\TwoFactorSecretCipher;
use App\User\Service\TwoFactorTotpService;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;

final class TwoFactorTotpServiceTest extends TestCase
{
    public function testVerifyLoginOtpRotatesLegacyEncryptedSecret(): void
    {
        $service = new TwoFactorTotpService($this->cipher('v2'));
        $secret = TOTP::generate()->getSecret();
        $state = [
            'enabled' => true,
            'secret_encrypted' => $this->cipher('v1')->encrypt($secret),
        ];

        self::assertTrue($service->verifyLoginOtp($state, TOTP::createFromSecret($secret)->now()));
        self::assertStringStartsWith('v2:', (string) ($state['secret_encrypted'] ?? null));
    }

    public function testDisableClearsStateToDisabledFlagOnly(): void
    {
        $secret = TOTP::generate()->getSecret();
        $service = new TwoFactorTotpService($this->cipher('v2'));
        $state = [
            'enabled' => true,
            'secret_encrypted' => $this->cipher('v2')->encrypt($secret),
            'recovery_code_hashes' => ['hash'],
        ];

        self::assertTrue($service->disable($state, TOTP::createFromSecret($secret)->now()));
        self::assertSame(['enabled' => false], $state);
    }

    private function cipher(string $activeVersion): TwoFactorSecretCipher
    {
        return new TwoFactorSecretCipher(
            'v1:BR/S2JUbOPhtWvvGSl7mme/p85UkTI3dxqWyj4eBJhs=,v2:WuCdN8gv+LrJgjvD+7nNe1DxvP/pbA4VOMdLhtGa1LU=',
            $activeVersion
        );
    }
}
