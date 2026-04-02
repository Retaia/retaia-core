<?php

namespace App\Tests\Unit\User;

use App\Tests\Support\FunctionalSchemaTrait;
use App\User\UserTwoFactorState;
use App\User\UserTwoFactorStateRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class UserTwoFactorStateRepositoryTest extends TestCase
{
    use FunctionalSchemaTrait;

    public function testSaveAndFindRoundTrip(): void
    {
        $repository = new UserTwoFactorStateRepository($this->connection());
        $state = new UserTwoFactorState(
            'u-1',
            true,
            'pending-secret',
            'active-secret',
            ['$argon2id$foo'],
            ['legacy-hash'],
            10,
            20,
        );

        $repository->save($state);
        $stored = $repository->findByUserId('u-1');

        self::assertNotNull($stored);
        self::assertSame('u-1', $stored->userId);
        self::assertTrue($stored->enabled);
        self::assertSame('pending-secret', $stored->pendingSecretEncrypted);
        self::assertSame('active-secret', $stored->secretEncrypted);
        self::assertSame(['$argon2id$foo'], $stored->recoveryCodeHashes);
        self::assertSame(['legacy-hash'], $stored->legacyRecoveryCodeSha256);
    }

    public function testDeleteRemovesState(): void
    {
        $repository = new UserTwoFactorStateRepository($this->connection());
        $repository->save(UserTwoFactorState::fromStateArray('u-2', [
            'enabled' => true,
            'secret_encrypted' => 'active-secret',
        ], null, 42));

        $repository->delete('u-2');

        self::assertNull($repository->findByUserId('u-2'));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->ensureUserTwoFactorStateTable($connection);

        return $connection;
    }
}
