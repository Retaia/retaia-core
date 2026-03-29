<?php

namespace App\Tests\Unit\Auth;

use App\Auth\UserAuthSession;
use App\Auth\UserAuthSessionRepository;
use App\Auth\UserAuthSessionService;
use App\Tests\Support\UserAuthSessionEntityManagerTrait;
use PHPUnit\Framework\TestCase;

final class UserAuthSessionServiceTest extends TestCase
{
    use UserAuthSessionEntityManagerTrait;

    public function testSessionsForUserSkipsExpiredSessionsAndKeepsCurrentFirst(): void
    {
        $service = new UserAuthSessionService(new UserAuthSessionRepository($this->userAuthSessionEntityManager()));
        $now = 2_000_000_000;

        $service->save(new UserAuthSession('s-1', 'a-1', 'r-1', $now + 60, $now + 3600, 'u-1', 'user@example.test', 'client-a', 'UI_WEB', $now - 20, $now - 10));
        $service->save(new UserAuthSession('s-2', 'a-2', 'r-2', $now + 60, 1, 'u-1', 'user@example.test', 'client-b', 'UI_WEB', $now - 20, $now - 5));

        $sessions = $service->sessionsForUser('u-1', 's-1');

        self::assertCount(1, $sessions);
        self::assertSame('s-1', $sessions[0]['session_id'] ?? null);
        self::assertTrue((bool) ($sessions[0]['is_current'] ?? false));
    }

}
