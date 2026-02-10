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
    ) {
    }

    /**
     * @return array<int, Job>
     */
    public function listClaimable(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, asset_uuid, job_type, status, claimed_by, lock_token, locked_until, result_payload
            FROM processing_job
            WHERE status = :pending OR (status = :claimed AND locked_until < :now)
            ORDER BY created_at ASC
            LIMIT :limit',
            [
                'pending' => JobStatus::PENDING->value,
                'claimed' => JobStatus::CLAIMED->value,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'limit' => $limit,
            ],
            [
                'limit' => ParameterType::INTEGER,
            ]
        );

        return array_map(fn (array $row): Job => $this->hydrate($row), $rows);
    }

    public function find(string $id): ?Job
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, asset_uuid, job_type, status, claimed_by, lock_token, locked_until, result_payload
             FROM processing_job
             WHERE id = :id',
            ['id' => $id]
        );

        return is_array($row) ? $this->hydrate($row) : null;
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
               AND (status = :pending OR (status = :claimed AND locked_until < :now))',
            [
                'claimed' => JobStatus::CLAIMED->value,
                'agentId' => $agentId,
                'lockToken' => $lockToken,
                'lockedUntil' => $lockedUntil,
                'id' => $id,
                'pending' => JobStatus::PENDING->value,
                'now' => $now,
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
        );
    }
}
