<?php

namespace App\Tests\Unit\Ingest\Repository;

use App\Ingest\Repository\IngestUnmatchedSidecarListingProjector;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class IngestUnmatchedSidecarListingProjectorTest extends TestCase
{
    public function testUnmatchedSnapshotFiltersByAllowedReasonAndSince(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('CREATE TABLE ingest_unmatched_sidecar (path VARCHAR(255) PRIMARY KEY, reason VARCHAR(64) NOT NULL, detected_at VARCHAR(32) NOT NULL)');
        $connection->insert('ingest_unmatched_sidecar', ['path' => 'INBOX/a.xmp', 'reason' => 'missing_parent', 'detected_at' => '2026-04-02 10:00:00']);
        $connection->insert('ingest_unmatched_sidecar', ['path' => 'INBOX/b.xmp', 'reason' => 'storage_mismatch', 'detected_at' => '2026-04-02 11:00:00']);
        $connection->insert('ingest_unmatched_sidecar', ['path' => 'INBOX/c.xmp', 'reason' => 'missing_parent', 'detected_at' => '2026-04-02 12:00:00']);

        $projector = new IngestUnmatchedSidecarListingProjector($connection);
        $snapshot = $projector->unmatchedSnapshot('missing_parent', new \DateTimeImmutable('2026-04-02 10:30:00+00:00'), 500);

        self::assertSame(1, $snapshot['total']);
        self::assertCount(1, $snapshot['items']);
        self::assertSame('INBOX/c.xmp', $snapshot['items'][0]['path']);
        self::assertSame('2026-04-02T12:00:00+00:00', $snapshot['items'][0]['detected_at']);
    }

    public function testUnknownReasonFallsBackToUnfilteredListing(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('CREATE TABLE ingest_unmatched_sidecar (path VARCHAR(255) PRIMARY KEY, reason VARCHAR(64) NOT NULL, detected_at VARCHAR(32) NOT NULL)');
        $connection->insert('ingest_unmatched_sidecar', ['path' => 'INBOX/a.xmp', 'reason' => 'missing_parent', 'detected_at' => '2026-04-02 10:00:00']);
        $connection->insert('ingest_unmatched_sidecar', ['path' => 'INBOX/b.xmp', 'reason' => 'storage_mismatch', 'detected_at' => '2026-04-02 11:00:00']);

        $projector = new IngestUnmatchedSidecarListingProjector($connection);
        $snapshot = $projector->unmatchedSnapshot('not-allowed', null, 10);

        self::assertSame(2, $snapshot['total']);
        self::assertCount(2, $snapshot['items']);
    }

    private function connection(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
    }
}
