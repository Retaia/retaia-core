<?php

namespace App\Tests\Unit\Ingest\Repository;

use App\Ingest\Repository\IngestUnmatchedSidecarWriter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class IngestUnmatchedSidecarWriterTest extends TestCase
{
    public function testRecordUpsertsAndClearDeletesNormalizedPath(): void
    {
        $connection = $this->connection();
        $this->createUnmatchedTable($connection);

        $writer = new IngestUnmatchedSidecarWriter($connection);
        $writer->recordUnmatchedSidecar('/INBOX/a.xmp', 'missing_parent');
        $writer->recordUnmatchedSidecar('INBOX/a.xmp', 'storage_mismatch');

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ingest_unmatched_sidecar'));
        self::assertSame('storage_mismatch', $connection->fetchOne('SELECT reason FROM ingest_unmatched_sidecar WHERE path = ?', ['INBOX/a.xmp']));

        $writer->clearUnmatchedSidecar('/INBOX/a.xmp');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ingest_unmatched_sidecar'));
    }

    private function connection(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
    }

    private function createUnmatchedTable(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE ingest_unmatched_sidecar (path VARCHAR(255) PRIMARY KEY, reason VARCHAR(64) NOT NULL, detected_at VARCHAR(32) NOT NULL)');
    }
}
