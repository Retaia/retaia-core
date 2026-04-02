<?php

namespace App\Tests\Unit\Lock;

use App\Lock\OperationLockType;
use App\Lock\Repository\OperationLockProjector;
use App\Observability\Repository\MetricEventRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class OperationLockProjectorTest extends TestCase
{
    public function testHasActiveLockRecordsMetricWhenLockExists(): void
    {
        $events = [];
        $connection = $this->connectionCollectingMetrics($events);
        $connection->method('fetchOne')->willReturn(1);

        $projector = new OperationLockProjector($connection, new MetricEventRepository($connection));

        self::assertTrue($projector->hasActiveLock('asset-1'));
        self::assertSame('lock.active.detected', $events[0]['metric_key'] ?? null);
    }

    public function testHasTypeLockRecordsMetricWhenTypeLockExists(): void
    {
        $events = [];
        $connection = $this->connectionCollectingMetrics($events);
        $connection->method('fetchOne')->willReturn(1);

        $projector = new OperationLockProjector($connection, new MetricEventRepository($connection));

        self::assertTrue($projector->hasTypeLock('asset-1', OperationLockType::MOVE));
        self::assertSame('lock.active.detected.asset_move_lock', $events[0]['metric_key'] ?? null);
    }

    public function testActiveLocksSnapshotNormalizesRowsAndIgnoresInvalidOnes(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willReturn(1);
        $connection->expects(self::once())->method('fetchOne')->willReturn(2);
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturn([
            [
                'id' => 'lock-1',
                'asset_uuid' => 'asset-1',
                'lock_type' => 'asset_move_lock',
                'actor_id' => 'actor-1',
                'acquired_at' => '2026-04-02 10:00:00',
                'released_at' => '',
            ],
            [
                'id' => '',
                'asset_uuid' => 'asset-2',
                'lock_type' => 'asset_move_lock',
                'actor_id' => 'actor-2',
                'acquired_at' => '2026-04-02 11:00:00',
                'released_at' => '',
            ],
        ]);

        $projector = new OperationLockProjector($connection, new MetricEventRepository($connection));
        $snapshot = $projector->activeLocksSnapshot('asset-1', 'asset_move_lock', 500, -10);

        self::assertSame(2, $snapshot['total']);
        self::assertCount(1, $snapshot['items']);
        self::assertSame('lock-1', $snapshot['items'][0]['id']);
        self::assertSame('2026-04-02T10:00:00+00:00', $snapshot['items'][0]['acquired_at']);
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
