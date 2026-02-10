<?php

namespace App\Tests\Unit\Command;

use App\Command\AlertsStateConflictsCommand;
use App\Observability\Repository\MetricEventRepository;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AlertsStateConflictsCommandTest extends TestCase
{
    public function testReturnsSuccessWhenCountsAreWithinThresholds(): void
    {
        $metrics = $this->repositoryWithSchema();
        $metrics->record('api.error.STATE_CONFLICT');
        $metrics->record('lock.acquire.failed.asset_move_lock');

        $tester = new CommandTester(new AlertsStateConflictsCommand($metrics));
        $exitCode = $tester->execute([
            '--window-minutes' => 15,
            '--state-conflicts-threshold' => 5,
            '--lock-failed-threshold' => 5,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No alert', $tester->getDisplay());
    }

    public function testReturnsFailureWhenThresholdIsExceeded(): void
    {
        $metrics = $this->repositoryWithSchema();
        for ($i = 0; $i < 3; ++$i) {
            $metrics->record('lock.acquire.failed.asset_move_lock');
        }

        $tester = new CommandTester(new AlertsStateConflictsCommand($metrics));
        $exitCode = $tester->execute([
            '--window-minutes' => 15,
            '--state-conflicts-threshold' => 5,
            '--lock-failed-threshold' => 2,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Alert threshold exceeded', $tester->getDisplay());
    }

    private function repositoryWithSchema(): MetricEventRepository
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement(
            'CREATE TABLE ops_metric_event (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                metric_key VARCHAR(128) NOT NULL,
                created_at DATETIME NOT NULL
            )'
        );

        return new MetricEventRepository($connection);
    }
}

