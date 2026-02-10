<?php

namespace App\Tests\Unit\Entity;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class PasswordResetTokenTest extends TestCase
{
    public function testTokenExposesUserIdHashAndExpiry(): void
    {
        $user = new User('user-9', 'user9@example.test', 'hash', ['ROLE_USER'], true);
        $expiresAt = new \DateTimeImmutable('+1 hour');
        $token = new PasswordResetToken($user, 'token-hash', $expiresAt);

        self::assertSame('user-9', $token->getUserId());
        self::assertSame('token-hash', $token->getTokenHash());
        self::assertSame($expiresAt, $token->getExpiresAt());
    }
}
