<?php

namespace App\Job\Repository;

use App\Job\JobStatus;
use Doctrine\DBAL\Connection;

final class JobLifecycleWriter
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function claim(string $id, string $agentId, int $ttlSeconds): bool
    {
        $lockToken = bin2hex(random_bytes(16));
        $lockedUntil = (new \DateTimeImmutable(sprintf('+%d seconds', max(1, $ttlSeconds))))->format('Y-m-d H:i:s');
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $affected = $this->connection->executeStatement(
            'UPDATE processing_job
             SET status = :claimed, claimed_by = :agentId, claimed_at = :now, lock_token = :lockToken, fencing_token = 1, locked_until = :lockedUntil, completed_by = NULL, completed_at = NULL, failed_by = NULL, failed_at = NULL, updated_at = :now
             WHERE id = :id
               AND (status = :pending OR (status = :claimed AND locked_until < :now))
               AND EXISTS (
                  SELECT 1
                  FROM asset a
                  WHERE a.uuid = processing_job.asset_uuid
                    AND a.state NOT IN (:blockedStateMoveQueued, :blockedStatePurged)
               )
               AND NOT EXISTS (
                  SELECT 1
                  FROM asset_operation_lock l
                  WHERE l.asset_uuid = processing_job.asset_uuid
                    AND l.released_at IS NULL
               )',
            [
                'claimed' => JobStatus::CLAIMED->value,
                'agentId' => $agentId,
                'lockToken' => $lockToken,
                'lockedUntil' => $lockedUntil,
                'id' => $id,
                'pending' => JobStatus::PENDING->value,
                'now' => $now,
                'blockedStateMoveQueued' => 'MOVE_QUEUED',
                'blockedStatePurged' => 'PURGED',
            ]
        );

        return $affected === 1;
    }

    public function heartbeat(string $id, string $actorId, string $lockToken, int $fencingToken, int $ttlSeconds): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $lockedUntil = (new \DateTimeImmutable(sprintf('+%d seconds', max(1, $ttlSeconds))))->format('Y-m-d H:i:s');

        $affected = $this->connection->executeStatement(
            'UPDATE processing_job
             SET locked_until = :lockedUntil, fencing_token = fencing_token + 1, updated_at = :now
             WHERE id = :id
               AND status = :claimed
               AND claimed_by = :actorId
               AND lock_token = :lockToken
               AND fencing_token = :fencingToken
               AND locked_until >= :now',
            [
                'id' => $id,
                'actorId' => $actorId,
                'claimed' => JobStatus::CLAIMED->value,
                'lockToken' => $lockToken,
                'fencingToken' => $fencingToken,
                'lockedUntil' => $lockedUntil,
                'now' => $now,
            ]
        );

        return $affected === 1;
    }

    /**
     * @param array<string, mixed> $result
     */
    public function submit(string $id, string $actorId, string $lockToken, int $fencingToken, array $result): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $affected = $this->connection->executeStatement(
            'UPDATE processing_job
             SET status = :completed, result_payload = :result, claimed_by = NULL, lock_token = NULL, fencing_token = NULL, locked_until = NULL, completed_by = :actorId, completed_at = :now, failed_by = NULL, failed_at = NULL, updated_at = :now
             WHERE id = :id
               AND status = :claimed
               AND claimed_by = :actorId
               AND lock_token = :lockToken
               AND fencing_token = :fencingToken
               AND locked_until >= :now',
            [
                'id' => $id,
                'actorId' => $actorId,
                'completed' => JobStatus::COMPLETED->value,
                'claimed' => JobStatus::CLAIMED->value,
                'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'lockToken' => $lockToken,
                'fencingToken' => $fencingToken,
                'now' => $now,
            ]
        );

        return $affected === 1;
    }

    public function fail(string $id, string $actorId, string $lockToken, int $fencingToken, bool $retryable, string $errorCode, string $message): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $nextStatus = $retryable ? JobStatus::PENDING->value : JobStatus::FAILED->value;
        $result = [
            'error_code' => $errorCode,
            'message' => $message,
            'retryable' => $retryable,
        ];

        $affected = $this->connection->executeStatement(
            'UPDATE processing_job
             SET status = :nextStatus, result_payload = :result, claimed_by = :nextClaimedBy, claimed_at = :nextClaimedAt, lock_token = NULL, fencing_token = NULL, locked_until = NULL, completed_by = NULL, completed_at = NULL, failed_by = :nextFailedBy, failed_at = :nextFailedAt, updated_at = :now
             WHERE id = :id
               AND status = :claimed
               AND claimed_by = :actorId
               AND lock_token = :lockToken
               AND fencing_token = :fencingToken
               AND locked_until >= :now',
            [
                'id' => $id,
                'actorId' => $actorId,
                'nextStatus' => $nextStatus,
                'nextClaimedBy' => $retryable ? null : $actorId,
                'nextClaimedAt' => null,
                'nextFailedBy' => $retryable ? null : $actorId,
                'nextFailedAt' => $retryable ? null : $now,
                'claimed' => JobStatus::CLAIMED->value,
                'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'lockToken' => $lockToken,
                'fencingToken' => $fencingToken,
                'now' => $now,
            ]
        );

        return $affected === 1;
    }

    public function hasActiveJobForAgent(string $agentId): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT 1
             FROM processing_job
             WHERE claimed_by = :agentId
               AND status = :claimed
               AND locked_until IS NOT NULL
               AND locked_until >= :now
             LIMIT 1',
            [
                'agentId' => $agentId,
                'claimed' => JobStatus::CLAIMED->value,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]
        );

        return $row !== false;
    }
}
