<?php

namespace App\Ingest\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class IngestUnmatchedSidecarWriter
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function recordUnmatchedSidecar(string $path, string $reason): void
    {
        $normalizedPath = ltrim(trim($path), '/');
        $normalizedReason = trim($reason);
        if ($normalizedPath === '' || $normalizedReason === '') {
            return;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        try {
            $this->connection->insert('ingest_unmatched_sidecar', [
                'path' => $normalizedPath,
                'reason' => $normalizedReason,
                'detected_at' => $now,
            ]);

            return;
        } catch (UniqueConstraintViolationException) {
        } catch (TableNotFoundException) {
            return;
        }

        try {
            $this->connection->update('ingest_unmatched_sidecar', [
                'reason' => $normalizedReason,
                'detected_at' => $now,
            ], [
                'path' => $normalizedPath,
            ]);
        } catch (TableNotFoundException) {
        }
    }

    public function clearUnmatchedSidecar(string $path): void
    {
        $normalizedPath = ltrim(trim($path), '/');
        if ($normalizedPath === '') {
            return;
        }

        try {
            $this->connection->delete('ingest_unmatched_sidecar', ['path' => $normalizedPath]);
        } catch (TableNotFoundException) {
        }
    }
}
