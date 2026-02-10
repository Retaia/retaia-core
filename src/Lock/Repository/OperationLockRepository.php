<?php

namespace App\Lock\Repository;

use App\Lock\OperationLockType;
use Doctrine\DBAL\Connection;

class OperationLockRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function hasActiveLock(string $assetUuid): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM asset_operation_lock WHERE asset_uuid = :assetUuid AND released_at IS NULL',
            ['assetUuid' => $assetUuid]
        ) > 0;
    }

    public function acquire(string $assetUuid, OperationLockType $type, string $actorId): bool
    {
        if ($this->hasTypeLock($assetUuid, $type)) {
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

            return true;
        } catch (\Throwable) {
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
    }

    private function hasTypeLock(string $assetUuid, OperationLockType $type): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM asset_operation_lock
             WHERE asset_uuid = :assetUuid
               AND lock_type = :lockType
               AND released_at IS NULL',
            [
                'assetUuid' => $assetUuid,
                'lockType' => $type->value,
            ]
        ) > 0;
    }
}
