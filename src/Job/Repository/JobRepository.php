<?php

namespace App\Job\Repository;

use App\Job\Job;
use App\Job\JobStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

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
            'SELECT j.id, j.asset_uuid, j.job_type, j.status, j.claimed_by, j.lock_token, j.locked_until, j.result_payload, a.fields AS asset_fields, a.filename AS asset_filename
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
            'SELECT j.id, j.asset_uuid, j.job_type, j.status, j.claimed_by, j.lock_token, j.locked_until, j.result_payload, a.fields AS asset_fields, a.filename AS asset_filename
             FROM processing_job j
             LEFT JOIN asset a ON a.uuid = j.asset_uuid
             WHERE j.id = :id',
            ['id' => $id]
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function hasJobForAssetAndType(string $assetUuid, string $jobType): bool
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id FROM processing_job WHERE asset_uuid = :assetUuid AND job_type = :jobType LIMIT 1',
            [
                'assetUuid' => $assetUuid,
                'jobType' => $jobType,
            ]
        );

        return is_array($row);
    }

    public function enqueuePending(string $assetUuid, string $jobType): Job
    {
        $id = bin2hex(random_bytes(16));
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->connection->insert('processing_job', [
            'id' => $id,
            'asset_uuid' => $assetUuid,
            'job_type' => $jobType,
            'status' => JobStatus::PENDING->value,
            'claimed_by' => null,
            'lock_token' => null,
            'locked_until' => null,
            'result_payload' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($id) ?? throw new \RuntimeException('Unable to load queued job.');
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
