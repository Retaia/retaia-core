<?php

namespace App\Tests\Unit\Auth;

use App\Auth\UserAuthSession;
use App\Auth\UserAuthSessionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class UserAuthSessionRepositoryTest extends TestCase
{
    public function testSaveAndFindBySessionIdRoundTripsSession(): void
    {
        $repository = new UserAuthSessionRepository($this->connection());
        $session = $this->session('s-1', 'r-1', 'u-1');

        $repository->save($session);

        self::assertEquals($session, $repository->findBySessionId('s-1'));
    }

    public function testSaveUpdatesExistingSession(): void
    {
        $repository = new UserAuthSessionRepository($this->connection());
        $repository->save($this->session('s-1', 'r-1', 'u-1'));
        $updated = $this->session('s-1', 'r-2', 'u-1')->withLastUsedAt(1234567999);

        $repository->save($updated);

        self::assertSame('r-2', $repository->findBySessionId('s-1')?->refreshToken);
        self::assertSame(1234567999, $repository->findBySessionId('s-1')?->lastUsedAt);
    }

    public function testFindByRefreshTokenAndUserIdAndDelete(): void
    {
        $repository = new UserAuthSessionRepository($this->connection());
        $repository->save($this->session('s-1', 'r-1', 'u-1'));
        $repository->save($this->session('s-2', 'r-2', 'u-1'));
        $repository->save($this->session('s-3', 'r-3', 'u-2'));

        self::assertSame('s-2', $repository->findByRefreshToken('r-2')?->sessionId);
        self::assertCount(2, $repository->findByUserId('u-1'));

        $repository->delete('s-2');

        self::assertNull($repository->findBySessionId('s-2'));
        self::assertCount(1, $repository->findByUserId('u-1'));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE user_auth_session (session_id VARCHAR(32) PRIMARY KEY NOT NULL, access_token CLOB NOT NULL, refresh_token VARCHAR(255) NOT NULL, access_expires_at INTEGER NOT NULL, refresh_expires_at INTEGER NOT NULL, user_id VARCHAR(32) NOT NULL, email VARCHAR(180) NOT NULL, client_id VARCHAR(64) NOT NULL, client_kind VARCHAR(32) NOT NULL, created_at INTEGER NOT NULL, last_used_at INTEGER NOT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX uniq_user_auth_session_refresh_token ON user_auth_session (refresh_token)');
        $connection->executeStatement('CREATE INDEX idx_user_auth_session_user_id ON user_auth_session (user_id)');

        return $connection;
    }

    private function session(string $sessionId, string $refreshToken, string $userId): UserAuthSession
    {
        return new UserAuthSession(
            $sessionId,
            'access-'.$sessionId,
            $refreshToken,
            1234567890,
            1234567990,
            $userId,
            $userId.'@example.test',
            'interactive-default',
            'UI_WEB',
            1234567800,
            1234567801,
        );
    }
}
