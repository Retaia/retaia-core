<?php

namespace App\Job\Repository;

use App\Job\Job;
use App\Job\JobStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class JobRepository
{
    public function __construct(
        private Connection $connection,
        private string $defaultStorageId = 'nas-main',
    ) {
    }

    /**
     * @return array<int, Job>
     */
    public function listClaimable(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT j.id, j.asset_uuid, j.job_type, j.status, j.claimed_by, j.lock_token, j.locked_until, j.result_payload, j.correlation_id, a.fields AS asset_fields, a.filename AS asset_filename
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
            'SELECT j.id, j.asset_uuid, j.job_type, j.status, j.claimed_by, j.lock_token, j.locked_until, j.result_payload, j.correlation_id, a.fields AS asset_fields, a.filename AS asset_filename
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
        $id = bin2hex(random_bytes(16));
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->connection->insert('processing_job', [
            'id' => $id,
            'asset_uuid' => $assetUuid,
            'job_type' => $jobType,
            'state_version' => $stateVersion,
            'status' => JobStatus::PENDING->value,
            'correlation_id' => null,
            'claimed_by' => null,
            'lock_token' => null,
            'locked_until' => null,
            'result_payload' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($id) ?? throw new \RuntimeException('Unable to load queued job.');
    }

    public function enqueuePendingIfMissing(
        string $assetUuid,
        string $jobType,
        string $stateVersion = '1',
        ?string $correlationId = null
    ): bool {
    {
        $id = bin2hex(random_bytes(16));
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            $this->connection->insert('processing_job', [
                'id' => $id,
                'asset_uuid' => $assetUuid,
                'job_type' => $jobType,
                'state_version' => $stateVersion,
                'status' => JobStatus::PENDING->value,
                'correlation_id' => $correlationId,
                'claimed_by' => null,
                'lock_token' => null,
                'locked_until' => null,
                'result_payload' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function claim(string $id, string $agentId, int $ttlSeconds): ?Job
    {
        $lockToken = bin2hex(random_bytes(16));
        $lockedUntil = (new \DateTimeImmutable(sprintf('+%d seconds', max(1, $ttlSeconds))))->format('Y-m-d H:i:s');
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $affected = $this->connection->executeStatement(
            'UPDATE processing_job
             SET status = :claimed, claimed_by = :agentId, lock_token = :lockToken, locked_until = :lockedUntil, updated_at = :now
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

    public function heartbeat(string $id, string $lockToken, int $ttlSeconds): ?Job
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $lockedUntil = (new \DateTimeImmutable(sprintf('+%d seconds', max(1, $ttlSeconds))))->format('Y-m-d H:i:s');

        $affected = $this->connection->executeStatement(
            'UPDATE processing_job
             SET locked_until = :lockedUntil, updated_at = :now
             WHERE id = :id
               AND status = :claimed
               AND lock_token = :lockToken
               AND locked_until >= :now',
            [
                'id' => $id,
                'claimed' => JobStatus::CLAIMED->value,
                'lockToken' => $lockToken,
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
    public function submit(string $id, string $lockToken, array $result): ?Job
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $affected = $this->connection->executeStatement(
            'UPDATE processing_job
             SET status = :completed, result_payload = :result, lock_token = NULL, locked_until = NULL, updated_at = :now
             WHERE id = :id
               AND status = :claimed
               AND lock_token = :lockToken
               AND locked_until >= :now',
            [
                'id' => $id,
                'completed' => JobStatus::COMPLETED->value,
                'claimed' => JobStatus::CLAIMED->value,
                'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'lockToken' => $lockToken,
                'now' => $now,
            ]
        );

        if ($affected !== 1) {
            return null;
        }

        return $this->find($id);
    }

    public function fail(string $id, string $lockToken, bool $retryable, string $errorCode, string $message): ?Job
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
             SET status = :nextStatus, result_payload = :result, lock_token = NULL, locked_until = NULL, updated_at = :now
             WHERE id = :id
               AND status = :claimed
               AND lock_token = :lockToken
               AND locked_until >= :now',
            [
                'id' => $id,
                'nextStatus' => $nextStatus,
                'claimed' => JobStatus::CLAIMED->value,
                'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'lockToken' => $lockToken,
                'now' => $now,
            ]
        );

        if ($affected !== 1) {
            return null;
        }

        return $this->find($id);
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

        $summary = [
            'pending_total' => 0,
            'claimed_total' => 0,
            'failed_total' => 0,
        ];
        foreach ($summaryRows as $row) {
            $status = trim((string) ($row['status'] ?? ''));
            $total = max(0, (int) ($row['total'] ?? 0));
            if ($status === JobStatus::PENDING->value) {
                $summary['pending_total'] = $total;
            } elseif ($status === JobStatus::CLAIMED->value) {
                $summary['claimed_total'] = $total;
            } elseif ($status === JobStatus::FAILED->value) {
                $summary['failed_total'] = $total;
            }
        }

        /** @var array<string, array{job_type:string,pending:int,claimed:int,failed:int,oldest_pending_age_seconds:?int}> $byType */
        $byType = [];
        foreach ($byTypeRows as $row) {
            $jobType = trim((string) ($row['job_type'] ?? ''));
            $status = trim((string) ($row['status'] ?? ''));
            $total = max(0, (int) ($row['total'] ?? 0));
            if ($jobType === '') {
                continue;
            }

            if (!isset($byType[$jobType])) {
                $byType[$jobType] = [
                    'job_type' => $jobType,
                    'pending' => 0,
                    'claimed' => 0,
                    'failed' => 0,
                    'oldest_pending_age_seconds' => null,
                ];
            }

            if ($status === JobStatus::PENDING->value) {
                $byType[$jobType]['pending'] = $total;
            } elseif ($status === JobStatus::CLAIMED->value) {
                $byType[$jobType]['claimed'] = $total;
            } elseif ($status === JobStatus::FAILED->value) {
                $byType[$jobType]['failed'] = $total;
            }
        }

        $now = new \DateTimeImmutable();
        foreach ($oldestPendingRows as $row) {
            $jobType = trim((string) ($row['job_type'] ?? ''));
            $oldestRaw = trim((string) ($row['oldest_pending_at'] ?? ''));
            if ($jobType === '' || !isset($byType[$jobType]) || $oldestRaw === '') {
                continue;
            }

            try {
                $oldest = new \DateTimeImmutable($oldestRaw);
                $age = max(0, $now->getTimestamp() - $oldest->getTimestamp());
                $byType[$jobType]['oldest_pending_age_seconds'] = $age;
            } catch (\Throwable) {
                $byType[$jobType]['oldest_pending_age_seconds'] = null;
            }
        }

        return [
            'summary' => $summary,
            'by_type' => array_values($byType),
        ];
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
            $this->sourceFromAssetFields($row['asset_fields'] ?? null, (string) ($row['asset_filename'] ?? '')),
            is_string($row['correlation_id'] ?? null) ? (string) $row['correlation_id'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceFromAssetFields(mixed $assetFieldsRaw, string $assetFilename): array
    {
        $fields = [];
        if (is_array($assetFieldsRaw)) {
            $fields = $assetFieldsRaw;
        } elseif (is_string($assetFieldsRaw) && $assetFieldsRaw !== '') {
            $decoded = json_decode($assetFieldsRaw, true);
            if (is_array($decoded)) {
                $fields = $decoded;
            }
        }

        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $storageId = trim((string) ($paths['storage_id'] ?? $fields['storage_id'] ?? $this->defaultStorageId));
        if ($storageId === '') {
            $storageId = $this->defaultStorageId;
        }

        $fallbackOriginal = $this->sanitizeRelativePath('INBOX/'.$assetFilename);
        $original = $this->sanitizeRelativePath((string) ($paths['original_relative'] ?? $fields['current_path'] ?? $fields['source_path'] ?? ''));
        if ($original === '') {
            $original = $fallbackOriginal;
        }
        $sidecars = $this->sanitizeRelativePaths(is_array($paths['sidecars_relative'] ?? null) ? $paths['sidecars_relative'] : ($fields['sidecars_relative'] ?? []));

        return [
            'storage_id' => $storageId,
            'original_relative' => $original,
            'sidecars_relative' => $sidecars,
        ];
    }

    private function sanitizeRelativePath(string $path): string
    {
        $trimmed = ltrim(trim($path), '/');
        if ($trimmed === '' || str_contains($trimmed, "\0") || str_contains($trimmed, '../') || str_contains($trimmed, '..\\')) {
            return '';
        }

        return $trimmed;
    }

    /**
     * @param mixed $paths
     * @return array<int, string>
     */
    private function sanitizeRelativePaths(mixed $paths): array
    {
        if (!is_array($paths)) {
            return [];
        }

        $result = [];
        foreach ($paths as $path) {
            $normalized = $this->sanitizeRelativePath((string) $path);
            if ($normalized !== '') {
                $result[] = $normalized;
            }
        }

        return array_values(array_unique($result));
    }
}
