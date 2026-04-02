<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\GetAuthMeProfileHandler;
use App\Entity\User;
use App\User\Repository\UserRepositoryInterface;
use App\User\Service\TwoFactorSecretCipher;
use App\User\Service\TwoFactorService;
use App\User\UserTwoFactorState;
use App\User\UserTwoFactorStateRepository;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class GetAuthMeProfileHandlerTest extends TestCase
{
    public function testHandleReturnsNormalizedMeProfile(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('findById')->with('u-1')->willReturn(
            new User('u-1', 'user@retaia.local', 'hash', ['ROLE_ADMIN'], true)
        );

        $handler = new GetAuthMeProfileHandler($users, $this->twoFactorService(enabledUserId: 'u-1'));
        $result = $handler->handle('u-1', 'user@retaia.local', ['ROLE_ADMIN']);

        self::assertSame('u-1', $result->id());
        self::assertSame('user@retaia.local', $result->email());
        self::assertSame(['ROLE_ADMIN'], $result->roles());
        self::assertSame('User', $result->displayName());
        self::assertTrue($result->emailVerified());
        self::assertTrue($result->mfaEnabled());
    }

    private function twoFactorService(string $enabledUserId): TwoFactorService
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE user_two_factor_state (user_id VARCHAR(32) PRIMARY KEY NOT NULL, enabled BOOLEAN NOT NULL, pending_secret_encrypted CLOB DEFAULT NULL, secret_encrypted CLOB DEFAULT NULL, recovery_code_hashes CLOB NOT NULL, legacy_recovery_code_sha256 CLOB NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)');
        $repository = new UserTwoFactorStateRepository($connection);
        $repository->save(new UserTwoFactorState($enabledUserId, true, null, 'active-secret', [], [], 1, 1));

        return new TwoFactorService(
            $repository,
            new TwoFactorSecretCipher('v1:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'v1')
        );
    }
}
