<?php

namespace App\Tests\Unit\User;

use App\Tests\Support\InMemoryUserRepository;
use App\Tests\Support\InMemoryPasswordResetTokenRepository;
use App\Tests\Support\TestUserPasswordHasher;
use App\User\Service\PasswordResetService;
use PHPUnit\Framework\TestCase;

final class PasswordResetServiceTest extends TestCase
{
    public function testResetFlowChangesStoredPasswordHash(): void
    {
        $users = new InMemoryUserRepository();
        $users->seedDefaultAdmin();
        $before = $users->findByEmail('admin@retaia.local');
        self::assertNotNull($before);
        $beforePasswordHash = $before->getPassword();

        $service = new PasswordResetService(
            $users,
            new InMemoryPasswordResetTokenRepository(),
            new TestUserPasswordHasher(),
            'test',
        );
        $token = $service->requestReset('admin@retaia.local');

        self::assertIsString($token);
        self::assertNotSame('', $token);
        self::assertTrue($service->resetPassword($token, 'new-password'));

        $after = $users->findByEmail('admin@retaia.local');
        self::assertNotNull($after);
        self::assertNotSame($beforePasswordHash, $after->getPassword());
        self::assertTrue(password_verify('new-password', $after->getPassword()));
    }

    public function testResetWithUnknownTokenReturnsFalse(): void
    {
        $users = new InMemoryUserRepository();
        $users->seedDefaultAdmin();
        $service = new PasswordResetService(
            $users,
            new InMemoryPasswordResetTokenRepository(),
            new TestUserPasswordHasher(),
            'test',
        );

        self::assertFalse($service->resetPassword('missing-token', 'new-password'));
    }
}
