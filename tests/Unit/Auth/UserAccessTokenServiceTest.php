<?php

namespace App\Tests\Unit\Auth;

use App\Auth\UserAccessTokenService;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class UserAccessTokenServiceTest extends TestCase
{
    public function testIssueIncludesRefreshTokenAndExpiresIn(): void
    {
        $service = new UserAccessTokenService(new ArrayAdapter(), 'test-secret', 3600, 86400);
        $user = new User('u-1', 'user@example.test', 'hash', ['ROLE_USER'], true);

        $payload = $service->issue($user, 'interactive-default', 'UI_WEB');

        self::assertIsString($payload['access_token'] ?? null);
        self::assertIsString($payload['refresh_token'] ?? null);
        self::assertSame(3600, $payload['expires_in'] ?? null);
    }

    public function testRefreshRotatesTokensAndInvalidatesPreviousAccessToken(): void
    {
        $service = new UserAccessTokenService(new ArrayAdapter(), 'test-secret', 3600, 86400);
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
        $service = new UserAccessTokenService(new ArrayAdapter(), 'test-secret', 3600, 86400);
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
        $service = new UserAccessTokenService(new ArrayAdapter(), 'test-secret', 3600, 86400);
        $user = new User('u-1', 'user@example.test', 'hash', ['ROLE_USER'], true);
        $issued = $service->issue($user, 'interactive-default', 'UI_WEB');

        self::assertNull($service->refresh((string) $issued['refresh_token'], 'other-client', null));
        self::assertNull($service->refresh((string) $issued['refresh_token'], null, 'AGENT'));
    }
}
