<?php

namespace App\Lock\Repository;

use App\Lock\OperationLockType;
use App\Observability\Repository\MetricEventRepository;
use Doctrine\DBAL\Connection;

class OperationLockRepository
{
    private OperationLockWriter $writer;
    private OperationLockProjector $projector;

    public function __construct(
        Connection $connection,
        MetricEventRepository $metrics,
        ?OperationLockWriter $writer = null,
        ?OperationLockProjector $projector = null,
    ) {
        $this->writer = $writer ?? new OperationLockWriter($connection, $metrics);
        $this->projector = $projector ?? new OperationLockProjector($connection, $metrics);
    }

    public function hasActiveLock(string $assetUuid): bool
    {
        return $this->projector->hasActiveLock($assetUuid);
    }

    public function acquire(string $assetUuid, OperationLockType $type, string $actorId): bool
    {
        return $this->writer->acquire($assetUuid, $type, $actorId, $this->projector->hasTypeLock($assetUuid, $type));
    }

    public function release(string $assetUuid, OperationLockType $type): void
    {
        $this->writer->release($assetUuid, $type);
    }

    public function countActiveLocks(): int
    {
        return $this->projector->countActiveLocks();
    }

    public function countStaleActiveLocks(\DateTimeImmutable $before): int
    {
        return $this->projector->countStaleActiveLocks($before);
    }

    public function countStaleActiveLocksByType(OperationLockType $type, \DateTimeImmutable $before): int
    {
        return $this->projector->countStaleActiveLocksByType($type, $before);
    }

    public function releaseStaleActiveLocksByType(OperationLockType $type, \DateTimeImmutable $before): int
    {
        return $this->writer->releaseStaleActiveLocksByType($type, $before);
    }

    /**
     * @return array{
     *     items:array<int, array{id:string,asset_uuid:string,lock_type:string,actor_id:string,acquired_at:string,released_at:?string}>,
     *     total:int
     * }
     */
    public function activeLocksSnapshot(?string $assetUuid = null, ?string $lockType = null, int $limit = 50, int $offset = 0): array
    {
        return $this->projector->activeLocksSnapshot($assetUuid, $lockType, $limit, $offset);
    }
}
