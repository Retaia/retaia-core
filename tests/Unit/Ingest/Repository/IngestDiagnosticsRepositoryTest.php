<?php

namespace App\Tests\Unit\Ingest\Repository;

use App\Ingest\Repository\IngestDiagnosticsRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class IngestDiagnosticsRepositoryTest extends TestCase
{
    public function testMissingOptionalTablesReturnEmptySnapshots(): void
    {
        $repository = new IngestDiagnosticsRepository($this->connection());

        self::assertSame([
            'queued' => 0,
            'missing' => 0,
            'unmatched_sidecars' => 0,
            'latest_unmatched' => [],
        ], $repository->diagnosticsSnapshot());
        self::assertSame([
            'items' => [],
            'total' => 0,
        ], $repository->unmatchedSnapshot(null, null));
    }

    public function testMissingOptionalTablesAreIgnoredForRecordAndClear(): void
    {
        $repository = new IngestDiagnosticsRepository($this->connection());

        $repository->recordUnmatchedSidecar('INBOX/a.xmp', 'missing_parent');
        $repository->clearUnmatchedSidecar('INBOX/a.xmp');

        self::assertTrue(true);
    }

    private function connection(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
    }
}
