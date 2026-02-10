<?php

namespace App\Tests\Unit\Ingest;

use App\Ingest\Repository\ScanStateStore;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ScanStateStoreTest extends TestCase
{
    public function testRecordDetectedFileTracksStableCountAcrossScans(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement('CREATE TABLE ingest_scan_file (path VARCHAR(1024) PRIMARY KEY NOT NULL, size_bytes INTEGER NOT NULL, mtime DATETIME NOT NULL, stable_count INTEGER NOT NULL, status VARCHAR(32) NOT NULL, first_seen_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL)');

        $store = new ScanStateStore($connection);
        $mtime = new \DateTimeImmutable('2026-01-01 10:00:00');
        $first = $store->recordDetectedFile('INBOX/test.mov', 100, $mtime, new \DateTimeImmutable('2026-01-01 10:01:00'));
        $second = $store->recordDetectedFile('INBOX/test.mov', 100, $mtime, new \DateTimeImmutable('2026-01-01 10:02:00'));
        $third = $store->recordDetectedFile('INBOX/test.mov', 200, new \DateTimeImmutable('2026-01-01 10:03:00'), new \DateTimeImmutable('2026-01-01 10:03:00'));

        self::assertSame('discovered', $first['status']);
        self::assertSame(1, $first['stable_count']);
        self::assertSame('stable', $second['status']);
        self::assertSame(2, $second['stable_count']);
        self::assertSame('discovered', $third['status']);
        self::assertSame(1, $third['stable_count']);

        $stable = $store->listStableFiles();
        self::assertCount(0, $stable);

        $store->recordDetectedFile('INBOX/test.mov', 200, new \DateTimeImmutable('2026-01-01 10:03:00'), new \DateTimeImmutable('2026-01-01 10:04:00'));
        $stable = $store->listStableFiles();
        self::assertCount(1, $stable);
        self::assertSame('INBOX/test.mov', $stable[0]['path']);

        $store->markQueued('INBOX/test.mov', new \DateTimeImmutable('2026-01-01 10:05:00'));
        self::assertCount(0, $store->listStableFiles());

        $store->markMissing('INBOX/test.mov', new \DateTimeImmutable('2026-01-01 10:06:00'));
        $status = (string) $connection->fetchOne('SELECT status FROM ingest_scan_file WHERE path = :path', ['path' => 'INBOX/test.mov']);
        self::assertSame('missing', $status);
    }
}
