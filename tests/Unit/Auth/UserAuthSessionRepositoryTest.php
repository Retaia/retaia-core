<?php

namespace App\Tests\Unit\Auth;

use App\Auth\UserAuthSession;
use App\Auth\UserAuthSessionRepository;
use App\Tests\Support\UserAuthSessionEntityManagerTrait;
use PHPUnit\Framework\TestCase;

final class UserAuthSessionRepositoryTest extends TestCase
{
    use UserAuthSessionEntityManagerTrait;

    public function testSaveAndFindBySessionIdRoundTripsSession(): void
    {
        $repository = new UserAuthSessionRepository($this->userAuthSessionEntityManager());
        $session = $this->session('s-1', 'r-1', 'u-1');

        $repository->save($session);

        self::assertEquals($session, $repository->findBySessionId('s-1'));
    }

    public function testSaveUpdatesExistingSession(): void
    {
        $repository = new UserAuthSessionRepository($this->userAuthSessionEntityManager());
        $repository->save($this->session('s-1', 'r-1', 'u-1'));
        $updated = $this->session('s-1', 'r-2', 'u-1')->withLastUsedAt(1234567999);

        $repository->save($updated);

        self::assertSame('r-2', $repository->findBySessionId('s-1')?->refreshToken);
        self::assertSame(1234567999, $repository->findBySessionId('s-1')?->lastUsedAt);
    }

    public function testFindByRefreshTokenAndUserIdAndDelete(): void
    {
        $repository = new UserAuthSessionRepository($this->userAuthSessionEntityManager());
        $repository->save($this->session('s-1', 'r-1', 'u-1'));
        $repository->save($this->session('s-2', 'r-2', 'u-1'));
        $repository->save($this->session('s-3', 'r-3', 'u-2'));

        self::assertSame('s-2', $repository->findByRefreshToken('r-2')?->sessionId);
        self::assertCount(2, $repository->findByUserId('u-1'));

        $repository->delete('s-2');

        self::assertNull($repository->findBySessionId('s-2'));
        self::assertCount(1, $repository->findByUserId('u-1'));
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
