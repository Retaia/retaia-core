<?php

namespace App\Lock\Repository;

use App\Lock\OperationLockType;
use App\Observability\MetricName;
use App\Observability\Repository\MetricEventRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class OperationLockProjector
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

    public function hasTypeLock(string $assetUuid, OperationLockType $type): bool
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
            }

            $releasedAt = null;
            $releasedRaw = trim((string) ($row['released_at'] ?? ''));
            if ($releasedRaw !== '') {
                $releasedAt = $releasedRaw;
                try {
                    $releasedAt = (new \DateTimeImmutable($releasedRaw))->format(DATE_ATOM);
                } catch (\Throwable) {
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
