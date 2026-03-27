<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\GetAuthMeProfileHandler;
use App\Entity\User;
use App\User\Repository\UserRepositoryInterface;
use App\User\Service\TwoFactorSecretCipher;
use App\User\Service\TwoFactorService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class GetAuthMeProfileHandlerTest extends TestCase
{
    public function testHandleReturnsNormalizedMeProfile(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('findById')->with('u-1')->willReturn(
            new User('u-1', 'user@retaia.local', 'hash', ['ROLE_ADMIN'], true)
        );

        $handler = new GetAuthMeProfileHandler($users, $this->twoFactorService(enabledUserId: 'u-1'));
        $result = $handler->handle('u-1', 'user@retaia.local', ['ROLE_ADMIN']);

        self::assertSame('u-1', $result->id());
        self::assertSame('user@retaia.local', $result->email());
        self::assertSame(['ROLE_ADMIN'], $result->roles());
        self::assertSame('User', $result->displayName());
        self::assertTrue($result->emailVerified());
        self::assertTrue($result->mfaEnabled());
    }

    private function twoFactorService(string $enabledUserId): TwoFactorService
    {
        $cache = new ArrayAdapter();
        $cacheItem = $cache->getItem('auth_2fa_'.sha1($enabledUserId));
        $cacheItem->set(['enabled' => true]);
        $cache->save($cacheItem);

        return new TwoFactorService(
            $cache,
            new TwoFactorSecretCipher('v1:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'v1')
        );
    }
}
