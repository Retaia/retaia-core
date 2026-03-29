<?php

namespace App\Tests\Unit\Auth;

use App\Auth\UserAccessJwtService;
use PHPUnit\Framework\TestCase;

final class UserAccessJwtServiceTest extends TestCase
{
    public function testIssueAndValidateRoundTrip(): void
    {
        $service = new UserAccessJwtService('test-secret', 3600);

        $issued = $service->issue('u-1', 'user@example.test', 'session-1', 'interactive-default', 'UI_WEB', time());
        $claims = $service->validate($issued['token']);

        self::assertIsArray($claims);
        self::assertSame('u-1', $claims['user_id'] ?? null);
        self::assertSame('session-1', $claims['session_id'] ?? null);
        self::assertSame('interactive-default', $claims['client_id'] ?? null);
    }
}
