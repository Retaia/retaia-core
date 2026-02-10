<?php

namespace App\Tests\Unit\Command;

use App\Command\PasswordResetCleanupCommand;
use App\User\Repository\PasswordResetTokenRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PasswordResetCleanupCommandTest extends TestCase
{
    public function testExecutePurgesExpiredTokensAndReturnsSuccess(): void
    {
        $repo = new class() implements PasswordResetTokenRepositoryInterface {
            public function save(string $userId, string $tokenHash, \DateTimeImmutable $expiresAt): void
            {
            }

            public function consumeValid(string $tokenHash, \DateTimeImmutable $now): ?string
            {
                return null;
            }

            public function purgeExpired(\DateTimeImmutable $now): int
            {
                return 3;
            }
        };

        $command = new PasswordResetCleanupCommand($repo);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Purged 3 expired password reset token(s).', $tester->getDisplay());
    }
}
