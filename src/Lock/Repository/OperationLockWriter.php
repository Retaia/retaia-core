<?php

namespace App\Lock\Repository;

use App\Lock\OperationLockType;
use App\Observability\MetricName;
use App\Observability\Repository\MetricEventRepository;
use Doctrine\DBAL\Connection;

final class OperationLockWriter
{
    public function __construct(
        private Connection $connection,
        private MetricEventRepository $metrics,
    ) {
    }

    public function acquire(string $assetUuid, OperationLockType $type, string $actorId, bool $typeAlreadyLocked): bool
    {
        if ($typeAlreadyLocked) {
            $this->metrics->record(MetricName::lockAcquireFailed($type->value));

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

            $this->metrics->record(MetricName::lockAcquireSuccess($type->value));

            return true;
        } catch (\Throwable) {
            $this->metrics->record(MetricName::lockAcquireFailed($type->value));

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
        $this->metrics->record(MetricName::lockRelease($type->value));
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
            $this->metrics->record(MetricName::lockWatchdogReleased($type->value));
        }

        return $released;
    }
}
