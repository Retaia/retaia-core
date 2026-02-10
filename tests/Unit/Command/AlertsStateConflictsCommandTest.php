<?php

namespace App\Tests\Unit\Command;

use App\Command\AlertsStateConflictsCommand;
use App\Lock\Repository\OperationLockRepository;
use App\Observability\Repository\MetricEventRepository;
use Doctrine\DBAL\Connection;
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
        $locks = $this->locksRepository(4, 0);

        $tester = new CommandTester(new AlertsStateConflictsCommand($metrics, $locks));
        $exitCode = $tester->execute([
            '--window-minutes' => 15,
            '--state-conflicts-threshold' => 5,
            '--lock-failed-threshold' => 5,
            '--active-locks-threshold' => 10,
            '--stale-locks-threshold' => 0,
            '--stale-lock-minutes' => 30,
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
        $locks = $this->locksRepository(3, 0);

        $tester = new CommandTester(new AlertsStateConflictsCommand($metrics, $locks));
        $exitCode = $tester->execute([
            '--window-minutes' => 15,
            '--state-conflicts-threshold' => 5,
            '--lock-failed-threshold' => 2,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Alert threshold exceeded', $tester->getDisplay());
    }

    public function testReturnsFailureWhenStaleLockThresholdIsExceeded(): void
    {
        $metrics = $this->repositoryWithSchema();
        $locks = $this->locksRepository(4, 2);

        $tester = new CommandTester(new AlertsStateConflictsCommand($metrics, $locks));
        $exitCode = $tester->execute([
            '--window-minutes' => 15,
            '--state-conflicts-threshold' => 20,
            '--lock-failed-threshold' => 10,
            '--active-locks-threshold' => 10,
            '--stale-locks-threshold' => 1,
            '--stale-lock-minutes' => 30,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('asset_operation_lock.stale(>30m)', $tester->getDisplay());
    }

    private function repositoryWithSchema(): MetricEventRepository
    {
        $events = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willReturnCallback(static function (string $table, array $data) use (&$events): int {
            if ($table === 'ops_metric_event') {
                $events[] = $data;
            }

            return 1;
        });
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql, array $params = []) use (&$events): int {
            $metricKey = (string) ($params['metricKey'] ?? '');
            $since = (string) ($params['since'] ?? '');
            $count = 0;
            foreach ($events as $event) {
                if (($event['metric_key'] ?? null) !== $metricKey) {
                    continue;
                }
                if ((string) ($event['created_at'] ?? '') < $since) {
                    continue;
                }
                ++$count;
            }

            return $count;
        });

        return new MetricEventRepository($connection);
    }

    private function locksRepository(int $activeLocks, int $staleLocks): OperationLockRepository
    {
        $locks = $this->createMock(OperationLockRepository::class);
        $locks->method('countActiveLocks')->willReturn($activeLocks);
        $locks->method('countStaleActiveLocks')->willReturn($staleLocks);

        return $locks;
    }
}
