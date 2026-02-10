<?php

namespace App\Tests\Unit\User;

use App\Tests\Support\InMemoryPasswordResetTokenRepository;
use App\Tests\Support\InMemoryUserRepository;
use App\Tests\Support\TestUserPasswordHasher;
use App\User\Service\PasswordResetService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PasswordResetServiceLoggingTest extends TestCase
{
    public function testRequestResetForUnknownUserLogsIgnoredEvent(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'auth.password_reset.request.ignored',
                self::callback(static function (array $context): bool {
                    return ($context['reason'] ?? null) === 'user_not_found'
                        && is_string($context['email_hash'] ?? null)
                        && ($context['email_hash'] ?? '') !== '';
                })
            );

        $service = new PasswordResetService(
            new InMemoryUserRepository(),
            new InMemoryPasswordResetTokenRepository(),
            new TestUserPasswordHasher(),
            $logger,
            'test',
            3600,
        );

        self::assertNull($service->requestReset('missing@retaia.local'));
    }

    public function testResetWithUnknownTokenLogsFailureReason(): void
    {
        $users = new InMemoryUserRepository();
        $users->seedDefaultAdmin();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'auth.password_reset.failed',
                self::callback(static fn (array $context): bool => ($context['reason'] ?? null) === 'invalid_or_expired_token')
            );

        $service = new PasswordResetService(
            $users,
            new InMemoryPasswordResetTokenRepository(),
            new TestUserPasswordHasher(),
            $logger,
            'test',
            3600,
        );

        self::assertFalse($service->resetPassword('missing-token', 'new-password'));
    }
}
