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

    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        if ($this->connection instanceof Connection) {
            $this->connection->close();
            $this->connection = null;
        }

        parent::tearDown();
    }

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
        self::assertSame(10, $stored->createdAt);
        self::assertSame(20, $stored->updatedAt);
    }

    public function testDeleteRemovesState(): void
    {
        $repository = new UserTwoFactorStateRepository($this->connection());
        $repository->save(UserTwoFactorState::fromStateArray('u-2', [
            'enabled' => true,
            'secret_encrypted' => 'active-secret',
        ], null, 42));

        $stored = $repository->findByUserId('u-2');

        self::assertNotNull($stored);
        self::assertSame('u-2', $stored->userId);
        self::assertTrue($stored->enabled);
        self::assertSame('active-secret', $stored->secretEncrypted);

        $repository->delete('u-2');

        self::assertNull($repository->findByUserId('u-2'));
    }

    public function testFindByUserIdReturnsNullForUnknownUser(): void
    {
        $repository = new UserTwoFactorStateRepository($this->connection());

        self::assertNull($repository->findByUserId('non-existent-user'));
    }

    public function testSaveUpdatesExistingRecordForSameUserId(): void
    {
        $repository = new UserTwoFactorStateRepository($this->connection());

        $repository->save(new UserTwoFactorState(
            'u-3',
            true,
            'initial-pending-secret',
            'initial-active-secret',
            ['$argon2id$initial'],
            ['initial-legacy-hash'],
            100,
            200,
        ));

        $repository->save(new UserTwoFactorState(
            'u-3',
            false,
            'updated-pending-secret',
            'updated-active-secret',
            ['$argon2id$updated'],
            ['updated-legacy-hash'],
            300,
            400,
        ));

        $stored = $repository->findByUserId('u-3');

        self::assertNotNull($stored);
        self::assertSame('u-3', $stored->userId);
        self::assertFalse($stored->enabled);
        self::assertSame('updated-pending-secret', $stored->pendingSecretEncrypted);
        self::assertSame('updated-active-secret', $stored->secretEncrypted);
        self::assertSame(['$argon2id$updated'], $stored->recoveryCodeHashes);
        self::assertSame(['updated-legacy-hash'], $stored->legacyRecoveryCodeSha256);
        self::assertSame(300, $stored->createdAt);
        self::assertSame(400, $stored->updatedAt);
        self::assertSame(1, $this->countRowsForUserId('u-3'));
    }

    private function connection(): Connection
    {
        if ($this->connection instanceof Connection) {
            return $this->connection;
        }

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->ensureUserTwoFactorStateTable($this->connection);

        return $this->connection;
    }

    private function countRowsForUserId(string $userId): int
    {
        $row = $this->connection()
            ->executeQuery('SELECT COUNT(*) AS cnt FROM user_two_factor_state WHERE user_id = ?', [$userId])
            ->fetchAssociative();

        self::assertIsArray($row);

        return (int) $row['cnt'];
    }
}
