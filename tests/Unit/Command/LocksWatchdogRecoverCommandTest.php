<?php

namespace App\Tests\Unit\Command;

use App\Command\LocksWatchdogRecoverCommand;
use App\Lock\Repository\OperationLockRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class LocksWatchdogRecoverCommandTest extends TestCase
{
    public function testDryRunReportsStaleLocksWithoutReleasing(): void
    {
        $locks = $this->createMock(OperationLockRepository::class);
        $locks->expects(self::exactly(2))
            ->method('countStaleActiveLocksByType')
            ->willReturnOnConsecutiveCalls(2, 1);
        $locks->expects(self::never())->method('releaseStaleActiveLocksByType');

        $tester = new CommandTester(new LocksWatchdogRecoverCommand($locks));
        $exitCode = $tester->execute([
            '--stale-lock-minutes' => 30,
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Dry-run complete', $tester->getDisplay());
        self::assertStringContainsString('asset_move_lock', $tester->getDisplay());
        self::assertStringContainsString('asset_purge_lock', $tester->getDisplay());
    }

    public function testRunReleasesStaleLocks(): void
    {
        $locks = $this->createMock(OperationLockRepository::class);
        $locks->expects(self::exactly(2))
            ->method('countStaleActiveLocksByType')
            ->willReturnOnConsecutiveCalls(3, 2);
        $locks->expects(self::exactly(2))
            ->method('releaseStaleActiveLocksByType')
            ->willReturnOnConsecutiveCalls(3, 1);

        $tester = new CommandTester(new LocksWatchdogRecoverCommand($locks));
        $exitCode = $tester->execute([
            '--stale-lock-minutes' => 45,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Released 4 stale lock(s)', $tester->getDisplay());
    }
}

