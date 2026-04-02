<?php

namespace App\Tests\Unit\Lock;

use App\Lock\OperationLockType;
use App\Lock\Repository\OperationLockWriter;
use App\Observability\Repository\MetricEventRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class OperationLockWriterTest extends TestCase
{
    public function testAcquireReturnsFalseAndRecordsMetricWhenTypeAlreadyLocked(): void
    {
        $events = [];
        $connection = $this->connectionCollectingMetrics($events);

        $writer = new OperationLockWriter($connection, new MetricEventRepository($connection));

        self::assertFalse($writer->acquire('asset-1', OperationLockType::MOVE, 'actor-1', true));
        self::assertSame(['lock.acquire.failed.asset_move_lock'], array_column($events, 'metric_key'));
    }

    public function testReleaseStaleActiveLocksRecordsMetricWhenRowsReleased(): void
    {
        $events = [];
        $connection = $this->connectionCollectingMetrics($events);
        $connection->method('executeStatement')->willReturn(2);

        $writer = new OperationLockWriter($connection, new MetricEventRepository($connection));
        $released = $writer->releaseStaleActiveLocksByType(OperationLockType::PURGE, new \DateTimeImmutable('-1 hour'));

        self::assertSame(2, $released);
        self::assertContains('lock.watchdog.released.asset_purge_lock', array_column($events, 'metric_key'));
    }

    private function connectionCollectingMetrics(array &$events): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willReturnCallback(static function (string $table, array $data) use (&$events): int {
            if ($table === 'ops_metric_event') {
                $events[] = $data;
            }

            return 1;
        });

        return $connection;
    }
}
