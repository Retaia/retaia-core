<?php

namespace App\Job\Repository;

use App\Job\JobStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class JobQueueWriter
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function enqueuePending(string $assetUuid, string $jobType, string $stateVersion = '1'): string
    {
        $id = bin2hex(random_bytes(16));
        $this->insertPending($id, $assetUuid, $jobType, $stateVersion, null);

        return $id;
    }

    public function enqueuePendingIfMissing(
        string $assetUuid,
        string $jobType,
        string $stateVersion = '1',
        ?string $correlationId = null
    ): bool {
        $id = bin2hex(random_bytes(16));

        try {
            $this->insertPending($id, $assetUuid, $jobType, $stateVersion, $correlationId);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    private function insertPending(
        string $id,
        string $assetUuid,
        string $jobType,
        string $stateVersion,
        ?string $correlationId
    ): void {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->connection->insert('processing_job', [
            'id' => $id,
            'asset_uuid' => $assetUuid,
            'job_type' => $jobType,
            'state_version' => $stateVersion,
            'status' => JobStatus::PENDING->value,
            'correlation_id' => $correlationId,
            'claimed_by' => null,
            'claimed_at' => null,
            'lock_token' => null,
            'fencing_token' => null,
            'locked_until' => null,
            'completed_by' => null,
            'completed_at' => null,
            'failed_by' => null,
            'failed_at' => null,
            'result_payload' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
