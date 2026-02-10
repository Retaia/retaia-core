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
}

