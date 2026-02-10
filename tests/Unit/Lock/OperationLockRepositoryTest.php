<?php

namespace App\Tests\Unit\Lock;

use App\Lock\OperationLockType;
use App\Lock\Repository\OperationLockRepository;
use App\Observability\Repository\MetricEventRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class OperationLockRepositoryTest extends TestCase
{
    public function testHasActiveLockRecordsMetricWhenLockExists(): void
    {
        $events = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willReturnCallback(static function (string $table, array $data) use (&$events): int {
            if ($table === 'ops_metric_event') {
                $events[] = $data;
            }

            return 1;
        });
        $connection->method('fetchOne')->willReturn(1);

        $metrics = new MetricEventRepository($connection);
        $repository = new OperationLockRepository($connection, $metrics);
        self::assertTrue($repository->hasActiveLock('asset-1'));
        self::assertSame('lock.active.detected', $events[0]['metric_key'] ?? null);
    }

    public function testAcquireRecordsTypeActiveMetricWhenTypeAlreadyLocked(): void
    {
        $events = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willReturnCallback(static function (string $table, array $data) use (&$events): int {
            if ($table === 'ops_metric_event') {
                $events[] = $data;
            }

            return 1;
        });
        $connection->method('fetchOne')->willReturn(1);

        $metrics = new MetricEventRepository($connection);
        $repository = new OperationLockRepository($connection, $metrics);
        self::assertFalse($repository->acquire('asset-1', OperationLockType::MOVE, 'actor-1'));
        self::assertSame(2, count($events));
        self::assertSame([
            'lock.active.detected.asset_move_lock',
            'lock.acquire.failed.asset_move_lock',
        ], array_values(array_map(static fn (array $event): string => (string) ($event['metric_key'] ?? ''), $events)));
    }

    public function testCountHelpersReadActiveAndStaleLocks(): void
    {
        $calls = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(static function () use (&$calls): int {
            ++$calls;

            return $calls === 1 ? 7 : 2;
        });

        $connection->method('insert')->willReturn(1);
        $metrics = new MetricEventRepository($connection);
        $repository = new OperationLockRepository($connection, $metrics);

        self::assertSame(7, $repository->countActiveLocks());
        self::assertSame(2, $repository->countStaleActiveLocks(new \DateTimeImmutable('-30 minutes')));
    }
}
