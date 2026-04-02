<?php

namespace App\Tests\Unit\Ingest\Repository;

use App\Ingest\Repository\IngestDiagnosticsSummaryProjector;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class IngestDiagnosticsSummaryProjectorTest extends TestCase
{
    public function testDiagnosticsSnapshotReturnsCountsAndLatestUnmatched(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('CREATE TABLE ingest_scan_file (status VARCHAR(32) NOT NULL)');
        $connection->executeStatement('CREATE TABLE ingest_unmatched_sidecar (path VARCHAR(255) PRIMARY KEY, reason VARCHAR(64) NOT NULL, detected_at VARCHAR(32) NOT NULL)');
        $connection->insert('ingest_scan_file', ['status' => 'queued']);
        $connection->insert('ingest_scan_file', ['status' => 'queued']);
        $connection->insert('ingest_scan_file', ['status' => 'missing']);
        $connection->insert('ingest_unmatched_sidecar', ['path' => 'INBOX/b.xmp', 'reason' => 'missing_parent', 'detected_at' => '2026-04-02 11:00:00']);
        $connection->insert('ingest_unmatched_sidecar', ['path' => 'INBOX/a.xmp', 'reason' => 'storage_mismatch', 'detected_at' => '2026-04-02 12:00:00']);

        $projector = new IngestDiagnosticsSummaryProjector($connection);
        $snapshot = $projector->diagnosticsSnapshot(1);

        self::assertSame(2, $snapshot['queued']);
        self::assertSame(1, $snapshot['missing']);
        self::assertSame(2, $snapshot['unmatched_sidecars']);
        self::assertCount(1, $snapshot['latest_unmatched']);
        self::assertSame('INBOX/a.xmp', $snapshot['latest_unmatched'][0]['path']);
        self::assertSame('2026-04-02T12:00:00+00:00', $snapshot['latest_unmatched'][0]['detected_at']);
    }

    private function connection(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
    }
}
