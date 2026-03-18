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

    public function testRefreshRejectsClientMismatch(): void
    {
        $service = new UserAccessTokenService(new ArrayAdapter(), 'test-secret', 3600, 86400);
        $user = new User('u-1', 'user@example.test', 'hash', ['ROLE_USER'], true);
        $issued = $service->issue($user, 'interactive-default', 'UI_WEB');

        self::assertNull($service->refresh((string) $issued['refresh_token'], 'other-client', null));
        self::assertNull($service->refresh((string) $issued['refresh_token'], null, 'AGENT'));
    }
}
