<?php

namespace App\Ingest\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\ParameterType;

final class IngestDiagnosticsSummaryProjector
{
    public function __construct(
        private Connection $connection,
    ) {
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
        } catch (TableNotFoundException) {
            return 0;
        }
    }

    private function countUnmatchedSidecars(): int
    {
        try {
            return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ingest_unmatched_sidecar');
        } catch (TableNotFoundException) {
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
        } catch (TableNotFoundException) {
            return [];
        }

        return IngestUnmatchedSidecarRowMapper::mapRows($rows);
    }
}
