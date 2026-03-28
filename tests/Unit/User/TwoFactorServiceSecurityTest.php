<?php

namespace App\Tests\Unit\User;

use App\User\Service\TwoFactorSecretCipher;
use App\User\Service\TwoFactorService;
use App\User\UserTwoFactorStateRepository;
use Doctrine\DBAL\DriverManager;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;

final class TwoFactorServiceSecurityTest extends TestCase
{
    public function testSecretsAreEncryptedAtRestAndRecoveryCodesUseArgon2id(): void
    {
        $repository = $this->repository();
        $service = new TwoFactorService($repository, $this->cipher('v2'));

        $setup = $service->setup('u-1', 'user@retaia.local');
        $secret = (string) ($setup['secret'] ?? '');
        self::assertNotSame('', $secret);

        $stateAfterSetup = $repository->findByUserId('u-1');
        self::assertNotNull($stateAfterSetup);
        self::assertNotNull($stateAfterSetup->pendingSecretEncrypted);
        self::assertStringNotContainsString($secret, $stateAfterSetup->pendingSecretEncrypted);

        self::assertTrue($service->enable('u-1', TOTP::createFromSecret($secret)->now()));
        $stateAfterEnable = $repository->findByUserId('u-1');
        self::assertNotNull($stateAfterEnable);
        self::assertNotNull($stateAfterEnable->secretEncrypted);
        self::assertStringNotContainsString($secret, $stateAfterEnable->secretEncrypted);

        $codes = $service->regenerateRecoveryCodes('u-1');
        self::assertCount(10, $codes);
        $stateAfterCodes = $repository->findByUserId('u-1');
        self::assertNotNull($stateAfterCodes);
        $hashes = $stateAfterCodes->recoveryCodeHashes;
        self::assertCount(10, $hashes);
        foreach ($hashes as $hash) {
            self::assertIsString($hash);
            self::assertStringStartsWith('$argon2id$', $hash);
        }
    }

    public function testRecoveryCodeIsOneShotAndKeyRotationReencryptsSecret(): void
    {
        $repository = $this->repository();
        $serviceV1 = new TwoFactorService($repository, $this->cipher('v1'));

        $setup = $serviceV1->setup('u-2', 'user@retaia.local');
        $secret = (string) ($setup['secret'] ?? '');
        self::assertNotSame('', $secret);
        self::assertTrue($serviceV1->enable('u-2', TOTP::createFromSecret($secret)->now()));
        $codes = $serviceV1->regenerateRecoveryCodes('u-2');
        $code = (string) ($codes[0] ?? '');
        self::assertNotSame('', $code);
        self::assertTrue($serviceV1->consumeRecoveryCode('u-2', $code));
        self::assertFalse($serviceV1->consumeRecoveryCode('u-2', $code));

        $stateBeforeRotation = $repository->findByUserId('u-2');
        self::assertNotNull($stateBeforeRotation);
        self::assertStringStartsWith('v1:', (string) $stateBeforeRotation->secretEncrypted);

        $serviceV2 = new TwoFactorService($repository, $this->cipher('v2'));
        self::assertTrue($serviceV2->verifyLoginOtp('u-2', TOTP::createFromSecret($secret)->now()));

        $stateAfterRotation = $repository->findByUserId('u-2');
        self::assertNotNull($stateAfterRotation);
        self::assertStringStartsWith('v2:', (string) $stateAfterRotation->secretEncrypted);
    }

    private function cipher(string $activeVersion): TwoFactorSecretCipher
    {
        return new TwoFactorSecretCipher(
            'v1:BR/S2JUbOPhtWvvGSl7mme/p85UkTI3dxqWyj4eBJhs=,v2:WuCdN8gv+LrJgjvD+7nNe1DxvP/pbA4VOMdLhtGa1LU=',
            $activeVersion
        );
    }

    private function repository(): UserTwoFactorStateRepository
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE user_two_factor_state (user_id VARCHAR(32) PRIMARY KEY NOT NULL, enabled BOOLEAN NOT NULL, pending_secret_encrypted CLOB DEFAULT NULL, secret_encrypted CLOB DEFAULT NULL, recovery_code_hashes CLOB NOT NULL, legacy_recovery_code_sha256 CLOB NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)');

        return new UserTwoFactorStateRepository($connection);
    }
}
