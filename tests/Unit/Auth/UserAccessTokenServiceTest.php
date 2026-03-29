<?php

namespace App\Tests\Unit\Auth;

use App\Auth\UserAccessTokenService;
use App\Auth\UserAccessJwtService;
use App\Auth\UserAuthSessionRepository;
use App\Auth\UserAuthSessionService;
use App\Entity\User;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class UserAccessTokenServiceTest extends TestCase
{
    public function testIssueIncludesRefreshTokenAndExpiresIn(): void
    {
        $service = $this->service();
        $user = new User('u-1', 'user@example.test', 'hash', ['ROLE_USER'], true);

        $payload = $service->issue($user, 'interactive-default', 'UI_WEB');

        self::assertIsString($payload['access_token'] ?? null);
        self::assertIsString($payload['refresh_token'] ?? null);
        self::assertSame(3600, $payload['expires_in'] ?? null);
    }

    public function testRefreshRotatesTokensAndInvalidatesPreviousAccessToken(): void
    {
        $service = $this->service();
        $user = new User('u-1', 'user@example.test', 'hash', ['ROLE_USER'], true);

        $firstIssue = $service->issue($user, 'interactive-default', 'UI_WEB');
        $refreshed = $service->refresh((string) $firstIssue['refresh_token']);

        self::assertIsArray($refreshed);
        self::assertNotSame($firstIssue['access_token'], $refreshed['access_token']);
        self::assertNotSame($firstIssue['refresh_token'], $refreshed['refresh_token']);
        self::assertNull($service->validate((string) $firstIssue['access_token']));
        self::assertIsArray($service->validate((string) $refreshed['access_token']));
    }

    public function testSessionsAreTrackedAndCanBeRevokedIndividually(): void
    {
        $service = $this->service();
        $user = new User('u-1', 'user@example.test', 'hash', ['ROLE_USER'], true);

        $firstIssue = $service->issue($user, 'interactive-a', 'UI_WEB');
        $secondIssue = $service->issue($user, 'interactive-b', 'UI_WEB');

        $current = $service->validate((string) $secondIssue['access_token']);
        self::assertIsArray($current);

        $sessions = $service->sessionsForUser('u-1', (string) $current['session_id']);
        self::assertCount(2, $sessions);
        self::assertTrue((bool) ($sessions[0]['is_current'] ?? false));

        $first = array_values(array_filter(
            $sessions,
            static fn (array $session): bool => ($session['client_id'] ?? null) === 'interactive-a'
        ));
        self::assertCount(1, $first);

        self::assertSame('REVOKED', $service->revokeSession('u-1', (string) $first[0]['session_id'], (string) $current['session_id']));
        self::assertNull($service->validate((string) $firstIssue['access_token']));
        self::assertIsArray($service->validate((string) $secondIssue['access_token']));
    }

    public function testRefreshRejectsClientMismatch(): void
    {
        $service = $this->service();
        $user = new User('u-1', 'user@example.test', 'hash', ['ROLE_USER'], true);
        $issued = $service->issue($user, 'interactive-default', 'UI_WEB');

        self::assertNull($service->refresh((string) $issued['refresh_token'], 'other-client', null));
        self::assertNull($service->refresh((string) $issued['refresh_token'], null, 'AGENT'));
    }

    private function service(): UserAccessTokenService
    {
        $repository = new UserAuthSessionRepository($this->connection());

        return new UserAccessTokenService(
            new UserAuthSessionService($repository),
            new UserAccessJwtService('test-secret', 3600),
            86400
        );
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
