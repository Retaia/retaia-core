<?php

namespace App\Lock\Repository;

use App\Lock\OperationLockType;
use App\Observability\MetricName;
use App\Observability\Repository\MetricEventRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

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
            $this->metrics->record(MetricName::LOCK_ACTIVE_DETECTED);
        }

        return $count > 0;
    }

    public function acquire(string $assetUuid, OperationLockType $type, string $actorId): bool
    {
        if ($this->hasTypeLock($assetUuid, $type)) {
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
            $this->metrics->record(MetricName::lockActiveDetectedByType($type->value));
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
            $this->metrics->record(MetricName::lockWatchdogReleased($type->value));
        }

        return $released;
    }

    /**
     * @return array{
     *     items:array<int, array{id:string,asset_uuid:string,lock_type:string,actor_id:string,acquired_at:string,released_at:?string}>,
     *     total:int
     * }
     */
    public function activeLocksSnapshot(?string $assetUuid = null, ?string $lockType = null, int $limit = 50, int $offset = 0): array
    {
        $whereParts = ['released_at IS NULL'];
        $params = [];
        $types = [];

        if (is_string($assetUuid) && trim($assetUuid) !== '') {
            $whereParts[] = 'asset_uuid = :assetUuid';
            $params['assetUuid'] = trim($assetUuid);
            $types['assetUuid'] = ParameterType::STRING;
        }

        if (is_string($lockType) && trim($lockType) !== '') {
            $whereParts[] = 'lock_type = :lockType';
            $params['lockType'] = trim($lockType);
            $types['lockType'] = ParameterType::STRING;
        }

        $where = ' WHERE '.implode(' AND ', $whereParts);
        $normalizedLimit = max(1, min(200, $limit));
        $normalizedOffset = max(0, $offset);

        try {
            $total = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM asset_operation_lock'.$where,
                $params,
                $types
            );
        } catch (\Throwable) {
            return ['items' => [], 'total' => 0];
        }

        $listParams = $params;
        $listParams['limit'] = $normalizedLimit;
        $listParams['offset'] = $normalizedOffset;
        $listTypes = $types;
        $listTypes['limit'] = ParameterType::INTEGER;
        $listTypes['offset'] = ParameterType::INTEGER;

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, asset_uuid, lock_type, actor_id, acquired_at, released_at
                 FROM asset_operation_lock'
                .$where.
                ' ORDER BY acquired_at DESC
                 LIMIT :limit OFFSET :offset',
                $listParams,
                $listTypes
            );
        } catch (\Throwable) {
            return ['items' => [], 'total' => 0];
        }

        $items = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            $asset = trim((string) ($row['asset_uuid'] ?? ''));
            $type = trim((string) ($row['lock_type'] ?? ''));
            $actor = trim((string) ($row['actor_id'] ?? ''));
            $acquiredRaw = trim((string) ($row['acquired_at'] ?? ''));
            if ($id === '' || $asset === '' || $type === '' || $actor === '' || $acquiredRaw === '') {
                continue;
            }

            $acquiredAt = $acquiredRaw;
            try {
                $acquiredAt = (new \DateTimeImmutable($acquiredRaw))->format(DATE_ATOM);
            } catch (\Throwable) {
                // Keep source value when parsing fails.
            }

            $releasedAt = null;
            $releasedRaw = trim((string) ($row['released_at'] ?? ''));
            if ($releasedRaw !== '') {
                $releasedAt = $releasedRaw;
                try {
                    $releasedAt = (new \DateTimeImmutable($releasedRaw))->format(DATE_ATOM);
                } catch (\Throwable) {
                    // Keep source value when parsing fails.
                }
            }

            $items[] = [
                'id' => $id,
                'asset_uuid' => $asset,
                'lock_type' => $type,
                'actor_id' => $actor,
                'acquired_at' => $acquiredAt,
                'released_at' => $releasedAt,
            ];
        }

        return ['items' => $items, 'total' => $total];
    }
}
