<?php

namespace App\Tests\Unit\User;

use App\User\Service\TwoFactorSecretCipher;
use App\User\Service\TwoFactorService;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class TwoFactorServiceSecurityTest extends TestCase
{
    public function testSecretsAreEncryptedAtRestAndRecoveryCodesUseArgon2id(): void
    {
        $cache = new ArrayAdapter();
        $service = new TwoFactorService($cache, $this->cipher('v2'));

        $setup = $service->setup('u-1', 'user@retaia.local');
        $secret = (string) ($setup['secret'] ?? '');
        self::assertNotSame('', $secret);

        $stateAfterSetup = $cache->getItem($this->cacheKey('u-1'))->get();
        self::assertIsArray($stateAfterSetup);
        self::assertArrayHasKey('pending_secret_encrypted', $stateAfterSetup);
        self::assertArrayNotHasKey('pending_secret', $stateAfterSetup);
        self::assertStringNotContainsString($secret, (string) $stateAfterSetup['pending_secret_encrypted']);

        self::assertTrue($service->enable('u-1', TOTP::createFromSecret($secret)->now()));
        $stateAfterEnable = $cache->getItem($this->cacheKey('u-1'))->get();
        self::assertIsArray($stateAfterEnable);
        self::assertArrayHasKey('secret_encrypted', $stateAfterEnable);
        self::assertArrayNotHasKey('secret', $stateAfterEnable);
        self::assertStringNotContainsString($secret, (string) $stateAfterEnable['secret_encrypted']);

        $codes = $service->regenerateRecoveryCodes('u-1');
        self::assertCount(10, $codes);
        $stateAfterCodes = $cache->getItem($this->cacheKey('u-1'))->get();
        self::assertIsArray($stateAfterCodes);
        $hashes = (array) ($stateAfterCodes['recovery_code_hashes'] ?? []);
        self::assertCount(10, $hashes);
        foreach ($hashes as $hash) {
            self::assertIsString($hash);
            self::assertStringStartsWith('$argon2id$', $hash);
        }
    }

    public function testRecoveryCodeIsOneShotAndKeyRotationReencryptsSecret(): void
    {
        $cache = new ArrayAdapter();
        $serviceV1 = new TwoFactorService($cache, $this->cipher('v1'));

        $setup = $serviceV1->setup('u-2', 'user@retaia.local');
        $secret = (string) ($setup['secret'] ?? '');
        self::assertNotSame('', $secret);
        self::assertTrue($serviceV1->enable('u-2', TOTP::createFromSecret($secret)->now()));
        $codes = $serviceV1->regenerateRecoveryCodes('u-2');
        $code = (string) ($codes[0] ?? '');
        self::assertNotSame('', $code);
        self::assertTrue($serviceV1->consumeRecoveryCode('u-2', $code));
        self::assertFalse($serviceV1->consumeRecoveryCode('u-2', $code));

        $stateBeforeRotation = $cache->getItem($this->cacheKey('u-2'))->get();
        self::assertIsArray($stateBeforeRotation);
        self::assertStringStartsWith('v1:', (string) ($stateBeforeRotation['secret_encrypted'] ?? ''));

        $serviceV2 = new TwoFactorService($cache, $this->cipher('v2'));
        self::assertTrue($serviceV2->verifyLoginOtp('u-2', TOTP::createFromSecret($secret)->now()));

        $stateAfterRotation = $cache->getItem($this->cacheKey('u-2'))->get();
        self::assertIsArray($stateAfterRotation);
        self::assertStringStartsWith('v2:', (string) ($stateAfterRotation['secret_encrypted'] ?? ''));
    }

    private function cipher(string $activeVersion): TwoFactorSecretCipher
    {
        return new TwoFactorSecretCipher(
            'v1:BR/S2JUbOPhtWvvGSl7mme/p85UkTI3dxqWyj4eBJhs=,v2:WuCdN8gv+LrJgjvD+7nNe1DxvP/pbA4VOMdLhtGa1LU=',
            $activeVersion
        );
    }

    private function cacheKey(string $userId): string
    {
        return 'auth_2fa_'.sha1($userId);
    }
}
