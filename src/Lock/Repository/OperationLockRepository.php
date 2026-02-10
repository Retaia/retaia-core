<?php

namespace App\Lock\Repository;

use App\Lock\OperationLockType;
use App\Observability\Repository\MetricEventRepository;
use Doctrine\DBAL\Connection;

class OperationLockRepository
{
    public function __construct(
        private Connection $connection,
        private MetricEventRepository $metrics,
    ) {
    }

    public function hasActiveLock(string $assetUuid): bool
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM asset_operation_lock WHERE asset_uuid = :assetUuid AND released_at IS NULL',
            ['assetUuid' => $assetUuid]
        );

        if ($count > 0) {
            $this->metrics->record('lock.active.detected');
        }

        return $count > 0;
    }

    public function acquire(string $assetUuid, OperationLockType $type, string $actorId): bool
    {
        if ($this->hasTypeLock($assetUuid, $type)) {
            $this->metrics->record(sprintf('lock.acquire.failed.%s', $type->value));
            return false;
        }

        try {
            $this->connection->insert('asset_operation_lock', [
                'id' => bin2hex(random_bytes(16)),
                'asset_uuid' => $assetUuid,
                'lock_type' => $type->value,
                'actor_id' => $actorId,
                'acquired_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'released_at' => null,
            ]);

            $this->metrics->record(sprintf('lock.acquire.success.%s', $type->value));
            return true;
        } catch (\Throwable) {
            $this->metrics->record(sprintf('lock.acquire.failed.%s', $type->value));
            return false;
        }
    }

    public function release(string $assetUuid, OperationLockType $type): void
    {
        $this->connection->executeStatement(
            'UPDATE asset_operation_lock
             SET released_at = :releasedAt
             WHERE asset_uuid = :assetUuid
               AND lock_type = :lockType
               AND released_at IS NULL',
            [
                'releasedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'assetUuid' => $assetUuid,
                'lockType' => $type->value,
            ]
        );
        $this->metrics->record(sprintf('lock.release.%s', $type->value));
    }

    private function hasTypeLock(string $assetUuid, OperationLockType $type): bool
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM asset_operation_lock
             WHERE asset_uuid = :assetUuid
               AND lock_type = :lockType
               AND released_at IS NULL',
            [
                'assetUuid' => $assetUuid,
                'lockType' => $type->value,
            ]
        );

        if ($count > 0) {
            $this->metrics->record(sprintf('lock.active.detected.%s', $type->value));
        }

        return $count > 0;
    }

    public function countActiveLocks(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM asset_operation_lock WHERE released_at IS NULL'
        );
    }

    public function countStaleActiveLocks(\DateTimeImmutable $before): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM asset_operation_lock
             WHERE released_at IS NULL
               AND acquired_at < :before',
            [
                'before' => $before->format('Y-m-d H:i:s'),
            ]
        );
    }

    public function countStaleActiveLocksByType(OperationLockType $type, \DateTimeImmutable $before): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM asset_operation_lock
             WHERE lock_type = :lockType
               AND released_at IS NULL
               AND acquired_at < :before',
            [
                'lockType' => $type->value,
                'before' => $before->format('Y-m-d H:i:s'),
            ]
        );
    }

    public function releaseStaleActiveLocksByType(OperationLockType $type, \DateTimeImmutable $before): int
    {
        $releasedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $released = $this->connection->executeStatement(
            'UPDATE asset_operation_lock
             SET released_at = :releasedAt
             WHERE lock_type = :lockType
               AND released_at IS NULL
               AND acquired_at < :before',
            [
                'releasedAt' => $releasedAt,
                'lockType' => $type->value,
                'before' => $before->format('Y-m-d H:i:s'),
            ]
        );

        if ($released > 0) {
            $this->metrics->record(sprintf('lock.watchdog.released.%s', $type->value));
        }

        return $released;
    }
}
