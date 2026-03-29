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
        private ?JobLifecycleWriter $lifecycleWriter = null,
    ) {
        $this->sourceProjector ??= new JobSourceProjector($storageRegistry);
        $this->queueWriter ??= new JobQueueWriter($connection);
        $this->lifecycleWriter ??= new JobLifecycleWriter($connection);
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
        if (!$this->lifecycleWriter->claim($id, $agentId, $ttlSeconds)) {
            return null;
        }

        return $this->find($id);
    }

    public function heartbeat(string $id, string $actorId, string $lockToken, int $fencingToken, int $ttlSeconds): ?Job
    {
        if (!$this->lifecycleWriter->heartbeat($id, $actorId, $lockToken, $fencingToken, $ttlSeconds)) {
            return null;
        }

        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $result
     */
    public function submit(string $id, string $actorId, string $lockToken, int $fencingToken, array $result): ?Job
    {
        if (!$this->lifecycleWriter->submit($id, $actorId, $lockToken, $fencingToken, $result)) {
            return null;
        }

        return $this->find($id);
    }

    public function fail(string $id, string $actorId, string $lockToken, int $fencingToken, bool $retryable, string $errorCode, string $message): ?Job
    {
        if (!$this->lifecycleWriter->fail($id, $actorId, $lockToken, $fencingToken, $retryable, $errorCode, $message)) {
            return null;
        }

        return $this->find($id);
    }

    public function hasActiveJobForAgent(string $agentId): bool
    {
        return $this->lifecycleWriter->hasActiveJobForAgent($agentId);
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
