<?php

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

final class UserCheckerTest extends TestCase
{
    public function testCheckPreAuthPassesWhenEmailIsVerified(): void
    {
        $checker = new UserChecker();
        $user = new User('user-verified', 'verified@example.test', password_hash('password', PASSWORD_DEFAULT), ['ROLE_USER'], true);

        $checker->checkPreAuth($user);

        self::assertTrue(true);
    }

    public function testCheckPreAuthThrowsWhenEmailIsNotVerified(): void
    {
        $checker = new UserChecker();
        $user = new User('user-pending', 'pending@example.test', password_hash('password', PASSWORD_DEFAULT), ['ROLE_USER'], false);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('EMAIL_NOT_VERIFIED');
        $checker->checkPreAuth($user);
    }

    public function testCheckPostAuthIsNoop(): void
    {
        $checker = new UserChecker();
        $user = new User('user-noop', 'noop@example.test', password_hash('password', PASSWORD_DEFAULT), ['ROLE_USER'], true);

        $checker->checkPostAuth($user);

        self::assertTrue(true);
    }
}
