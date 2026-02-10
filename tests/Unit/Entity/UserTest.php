<?php

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testUserAccessorsAndMutators(): void
    {
        $user = new User('user-1', 'user@example.test', 'hash-1', ['ROLE_ADMIN'], false);

        self::assertSame('user-1', $user->getId());
        self::assertSame('user@example.test', $user->getEmail());
        self::assertSame('user@example.test', $user->getUserIdentifier());
        self::assertSame('hash-1', $user->getPassword());
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
        self::assertFalse($user->isEmailVerified());

        $returned = $user->withEmailVerified(true)->withPasswordHash('hash-2');

        self::assertSame($user, $returned);
        self::assertTrue($user->isEmailVerified());
        self::assertSame('hash-2', $user->getPassword());

        $user->eraseCredentials();
        self::assertTrue(true);
    }
}
