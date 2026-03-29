<?php

namespace App\Tests\Unit\Auth;

use App\Auth\UserAuthSession;
use App\Auth\UserAuthSessionRepository;
use App\Auth\UserAuthSessionService;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class UserAuthSessionServiceTest extends TestCase
{
    public function testSessionsForUserSkipsExpiredSessionsAndKeepsCurrentFirst(): void
    {
        $service = new UserAuthSessionService(new UserAuthSessionRepository($this->connection()));
        $now = 2_000_000_000;

        $service->save(new UserAuthSession('s-1', 'a-1', 'r-1', $now + 60, $now + 3600, 'u-1', 'user@example.test', 'client-a', 'UI_WEB', $now - 20, $now - 10));
        $service->save(new UserAuthSession('s-2', 'a-2', 'r-2', $now + 60, 1, 'u-1', 'user@example.test', 'client-b', 'UI_WEB', $now - 20, $now - 5));

        $sessions = $service->sessionsForUser('u-1', 's-1');

        self::assertCount(1, $sessions);
        self::assertSame('s-1', $sessions[0]['session_id'] ?? null);
        self::assertTrue((bool) ($sessions[0]['is_current'] ?? false));
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE user_auth_session (session_id VARCHAR(32) PRIMARY KEY NOT NULL, access_token CLOB NOT NULL, refresh_token VARCHAR(255) NOT NULL, access_expires_at INTEGER NOT NULL, refresh_expires_at INTEGER NOT NULL, user_id VARCHAR(32) NOT NULL, email VARCHAR(180) NOT NULL, client_id VARCHAR(64) NOT NULL, client_kind VARCHAR(32) NOT NULL, created_at INTEGER NOT NULL, last_used_at INTEGER NOT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX uniq_user_auth_session_refresh_token ON user_auth_session (refresh_token)');
        $connection->executeStatement('CREATE INDEX idx_user_auth_session_user_id ON user_auth_session (user_id)');

        return $connection;
    }
}
