<?php

namespace App\Job\Repository;

use App\Job\Job;
use App\Job\JobStatus;
use App\Storage\BusinessStorageRegistryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class JobRepository
{
    public function __construct(
        private Connection $connection,
        BusinessStorageRegistryInterface $storageRegistry,
        private JobQueueDiagnosticsProjector $diagnosticsProjector = new JobQueueDiagnosticsProjector(),
        private ?JobSourceProjector $sourceProjector = null,
        private ?JobQueueWriter $queueWriter = null,
    ) {
        $this->sourceProjector ??= new JobSourceProjector($storageRegistry);
        $this->queueWriter ??= new JobQueueWriter($connection);
    }

    /**
     * @return array<int, Job>
     */
    public function listClaimable(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT j.id, j.asset_uuid, j.job_type, j.status, j.claimed_by, j.lock_token, j.fencing_token, j.locked_until, j.result_payload, j.correlation_id, a.fields AS asset_fields, a.filename AS asset_filename
            FROM processing_job j
            INNER JOIN asset a ON a.uuid = j.asset_uuid
            WHERE (j.status = :pending OR (j.status = :claimed AND j.locked_until < :now))
              AND a.state NOT IN (:blockedStateMoveQueued, :blockedStatePurged)
              AND NOT EXISTS (
                  SELECT 1
                  FROM asset_operation_lock l
                  WHERE l.asset_uuid = j.asset_uuid
                    AND l.released_at IS NULL
              )
            ORDER BY j.created_at ASC
            LIMIT :limit',
            [
                'pending' => JobStatus::PENDING->value,
                'claimed' => JobStatus::CLAIMED->value,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'blockedStateMoveQueued' => 'MOVE_QUEUED',
                'blockedStatePurged' => 'PURGED',
                'limit' => $limit,
            ],
            [
                'blockedStateMoveQueued' => ParameterType::STRING,
                'blockedStatePurged' => ParameterType::STRING,
                'limit' => ParameterType::INTEGER,
            ]
        );

        return array_map(fn (array $row): Job => $this->hydrate($row), $rows);
    }

    public function find(string $id): ?Job
    {
        $row = $this->connection->fetchAssociative(
            'SELECT j.id, j.asset_uuid, j.job_type, j.status, j.claimed_by, j.lock_token, j.fencing_token, j.locked_until, j.result_payload, j.correlation_id, a.fields AS asset_fields, a.filename AS asset_filename
             FROM processing_job j
             LEFT JOIN asset a ON a.uuid = j.asset_uuid
             WHERE j.id = :id',
            ['id' => $id]
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function hasJobForAssetAndType(string $assetUuid, string $jobType, ?string $stateVersion = null): bool
    {
        if ($stateVersion === null) {
            $row = $this->connection->fetchAssociative(
                'SELECT id FROM processing_job WHERE asset_uuid = :assetUuid AND job_type = :jobType LIMIT 1',
                [
                    'assetUuid' => $assetUuid,
                    'jobType' => $jobType,
                ]
            );
        } else {
            $row = $this->connection->fetchAssociative(
                'SELECT id FROM processing_job WHERE asset_uuid = :assetUuid AND job_type = :jobType AND state_version = :stateVersion LIMIT 1',
                [
                    'assetUuid' => $assetUuid,
                    'jobType' => $jobType,
                    'stateVersion' => $stateVersion,
                ]
            );
        }

        return is_array($row);
    }

    public function enqueuePending(string $assetUuid, string $jobType, string $stateVersion = '1'): Job
    {
        $id = $this->queueWriter->enqueuePending($assetUuid, $jobType, $stateVersion);

        return $this->find($id) ?? throw new \RuntimeException('Unable to load queued job.');
    }

    public function enqueuePendingIfMissing(
        string $assetUuid,
        string $jobType,
        string $stateVersion = '1',
        ?string $correlationId = null
    ): bool {
        return $this->queueWriter->enqueuePendingIfMissing($assetUuid, $jobType, $stateVersion, $correlationId);
    }

    public function claim(string $id, string $agentId, int $ttlSeconds): ?Job
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

        if ($affected !== 1) {
            return null;
        }

        return $this->find($id);
    }

    public function heartbeat(string $id, string $actorId, string $lockToken, int $fencingToken, int $ttlSeconds): ?Job
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

        if ($affected !== 1) {
            return null;
        }

        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $result
     */
    public function submit(string $id, string $actorId, string $lockToken, int $fencingToken, array $result): ?Job
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

        if ($affected !== 1) {
            return null;
        }

        return $this->find($id);
    }

    public function fail(string $id, string $actorId, string $lockToken, int $fencingToken, bool $retryable, string $errorCode, string $message): ?Job
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

        if ($affected !== 1) {
            return null;
        }

        return $this->find($id);
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

    /**
     * @return array{
     *     summary:array{pending_total:int,claimed_total:int,failed_total:int},
     *     by_type:array<int, array{job_type:string,pending:int,claimed:int,failed:int,oldest_pending_age_seconds:?int}>
     * }
     */
    public function queueDiagnosticsSnapshot(): array
    {
        try {
            $summaryRows = $this->connection->fetchAllAssociative(
                'SELECT status, COUNT(*) AS total
                 FROM processing_job
                 GROUP BY status'
            );
            $byTypeRows = $this->connection->fetchAllAssociative(
                'SELECT job_type, status, COUNT(*) AS total
                 FROM processing_job
                 GROUP BY job_type, status
                 ORDER BY job_type ASC'
            );
            $oldestPendingRows = $this->connection->fetchAllAssociative(
                'SELECT job_type, MIN(created_at) AS oldest_pending_at
                 FROM processing_job
                 WHERE status = :pending
                 GROUP BY job_type',
                ['pending' => JobStatus::PENDING->value]
            );
        } catch (\Throwable) {
            return [
                'summary' => [
                    'pending_total' => 0,
                    'claimed_total' => 0,
                    'failed_total' => 0,
                ],
                'by_type' => [],
            ];
        }

        return $this->diagnosticsProjector->project($summaryRows, $byTypeRows, $oldestPendingRows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Job
    {
        $lockedUntil = null;
        if (is_string($row['locked_until'] ?? null) && $row['locked_until'] !== '') {
            $lockedUntil = new \DateTimeImmutable((string) $row['locked_until']);
        }

        $result = [];
        if (is_string($row['result_payload'] ?? null) && $row['result_payload'] !== '') {
            $decoded = json_decode((string) $row['result_payload'], true);
            $result = is_array($decoded) ? $decoded : [];
        }

        return new Job(
            (string) $row['id'],
            (string) $row['asset_uuid'],
            (string) $row['job_type'],
            JobStatus::from((string) $row['status']),
            isset($row['claimed_by']) ? (string) $row['claimed_by'] : null,
            isset($row['lock_token']) ? (string) $row['lock_token'] : null,
            $lockedUntil,
            $result,
            $this->sourceProjector->sourceFromAssetFields($row['asset_fields'] ?? null, (string) ($row['asset_filename'] ?? '')),
            is_string($row['correlation_id'] ?? null) ? (string) $row['correlation_id'] : null,
            isset($row['fencing_token']) ? (int) $row['fencing_token'] : null,
        );
    }
}
