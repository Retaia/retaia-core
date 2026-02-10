<?php

namespace App\Ingest\Repository;

use App\Ingest\Port\ScanStateStoreInterface;
use Doctrine\DBAL\Connection;

final class ScanStateStore implements ScanStateStoreInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function recordDetectedFile(string $path, int $size, \DateTimeImmutable $mtime, \DateTimeImmutable $scannedAt): array
    {
        $existing = $this->connection->fetchAssociative(
            'SELECT path, size_bytes, mtime, stable_count, status, first_seen_at, last_seen_at
             FROM ingest_scan_file
             WHERE path = :path',
            ['path' => $path]
        );

        if (is_array($existing)) {
            $sameSize = (int) $existing['size_bytes'] === $size;
            $sameMtime = (string) $existing['mtime'] === $mtime->format('Y-m-d H:i:s');
            $stableCount = ($sameSize && $sameMtime) ? ((int) $existing['stable_count'] + 1) : 1;
            $status = $stableCount >= 2 ? 'stable' : 'discovered';

            $this->connection->update(
                'ingest_scan_file',
                [
                    'size_bytes' => $size,
                    'mtime' => $mtime->format('Y-m-d H:i:s'),
                    'stable_count' => $stableCount,
                    'status' => $status,
                    'last_seen_at' => $scannedAt->format('Y-m-d H:i:s'),
                ],
                ['path' => $path]
            );

            return [
                'path' => $path,
                'size' => $size,
                'mtime' => $mtime,
                'stable_count' => $stableCount,
                'status' => $status,
                'first_seen_at' => new \DateTimeImmutable((string) $existing['first_seen_at']),
                'last_seen_at' => $scannedAt,
            ];
        }

        $this->connection->insert(
            'ingest_scan_file',
            [
                'path' => $path,
                'size_bytes' => $size,
                'mtime' => $mtime->format('Y-m-d H:i:s'),
                'stable_count' => 1,
                'status' => 'discovered',
                'first_seen_at' => $scannedAt->format('Y-m-d H:i:s'),
                'last_seen_at' => $scannedAt->format('Y-m-d H:i:s'),
            ]
        );

        return [
            'path' => $path,
            'size' => $size,
            'mtime' => $mtime,
            'stable_count' => 1,
            'status' => 'discovered',
            'first_seen_at' => $scannedAt,
            'last_seen_at' => $scannedAt,
        ];
    }

    public function listStableFiles(int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT path, size_bytes, mtime, stable_count, status
             FROM ingest_scan_file
             WHERE status = :status
             ORDER BY last_seen_at ASC
             LIMIT :limit',
            [
                'status' => 'stable',
                'limit' => max(1, $limit),
            ],
            [
                'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
            ]
        );

        return array_map(static fn (array $row): array => [
            'path' => (string) $row['path'],
            'size' => (int) $row['size_bytes'],
            'mtime' => new \DateTimeImmutable((string) $row['mtime']),
            'stable_count' => (int) $row['stable_count'],
            'status' => (string) $row['status'],
        ], $rows);
    }

    public function markQueued(string $path, \DateTimeImmutable $queuedAt): void
    {
        $this->connection->update('ingest_scan_file', [
            'status' => 'queued',
            'last_seen_at' => $queuedAt->format('Y-m-d H:i:s'),
        ], [
            'path' => $path,
        ]);
    }

    public function markMissing(string $path, \DateTimeImmutable $at): void
    {
        $this->connection->update('ingest_scan_file', [
            'status' => 'missing',
            'last_seen_at' => $at->format('Y-m-d H:i:s'),
        ], [
            'path' => $path,
        ]);
    }
}
