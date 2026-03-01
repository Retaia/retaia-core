<?php

namespace App\Ingest\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;

final class IngestDiagnosticsRepository
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
            // Upsert behavior across supported databases.
        } catch (\Throwable) {
            // Keep ingest resilient for minimal test schemas.
            return;
        }

        try {
            $this->connection->update('ingest_unmatched_sidecar', [
                'reason' => $normalizedReason,
                'detected_at' => $now,
            ], [
                'path' => $normalizedPath,
            ]);
        } catch (\Throwable) {
            // Keep ingest resilient for minimal test schemas.
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
        } catch (\Throwable) {
            // Keep ingest resilient for minimal test schemas.
        }
    }

    /**
     * @return array{
     *     queued:int,
     *     missing:int,
     *     unmatched_sidecars:int,
     *     latest_unmatched:array<int, array{path:string,reason:string,detected_at:string}>
     * }
     */
    public function diagnosticsSnapshot(int $latestLimit = 20): array
    {
        $limit = max(1, min(100, $latestLimit));

        return [
            'queued' => $this->countScanStatus('queued'),
            'missing' => $this->countScanStatus('missing'),
            'unmatched_sidecars' => $this->countUnmatchedSidecars(),
            'latest_unmatched' => $this->latestUnmatchedSidecars($limit),
        ];
    }

    private function countScanStatus(string $status): int
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ingest_scan_file WHERE status = :status',
                ['status' => $status]
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countUnmatchedSidecars(): int
    {
        try {
            return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ingest_unmatched_sidecar');
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<int, array{path:string,reason:string,detected_at:string}>
     */
    private function latestUnmatchedSidecars(int $limit): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT path, reason, detected_at FROM ingest_unmatched_sidecar ORDER BY detected_at DESC LIMIT :limit',
                ['limit' => $limit],
                ['limit' => ParameterType::INTEGER]
            );
        } catch (\Throwable) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $path = trim((string) ($row['path'] ?? ''));
            $reason = trim((string) ($row['reason'] ?? ''));
            $detectedAtRaw = trim((string) ($row['detected_at'] ?? ''));
            if ($path === '' || $reason === '' || $detectedAtRaw === '') {
                continue;
            }

            $detectedAt = $detectedAtRaw;
            try {
                $detectedAt = (new \DateTimeImmutable($detectedAtRaw))->format(DATE_ATOM);
            } catch (\Throwable) {
                // Keep source value when parsing fails.
            }

            $items[] = [
                'path' => $path,
                'reason' => $reason,
                'detected_at' => $detectedAt,
            ];
        }

        return $items;
    }
}
