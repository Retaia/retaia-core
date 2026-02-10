<?php

namespace App\Tests\Unit\Ingest;

use App\Ingest\Repository\ScanStateStore;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class ScanStateStoreTest extends TestCase
{
    public function testRecordDetectedFileTracksStableCountAcrossScans(): void
    {
        $rows = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(static function (string $sql, array $params = []) use (&$rows): array|false {
            $path = (string) ($params['path'] ?? '');

            return $rows[$path] ?? false;
        });
        $connection->method('insert')->willReturnCallback(static function (string $table, array $data) use (&$rows): int {
            if ($table === 'ingest_scan_file') {
                $rows[(string) $data['path']] = $data;
            }

            return 1;
        });
        $connection->method('update')->willReturnCallback(static function (string $table, array $data, array $criteria) use (&$rows): int {
            $path = (string) ($criteria['path'] ?? '');
            if ($table !== 'ingest_scan_file' || $path === '' || !isset($rows[$path])) {
                return 0;
            }

            $rows[$path] = array_merge($rows[$path], $data);

            return 1;
        });
        $connection->method('fetchAllAssociative')->willReturnCallback(static function (string $sql, array $params = []) use (&$rows): array {
            $status = (string) ($params['status'] ?? '');
            $limit = (int) ($params['limit'] ?? 100);
            $filtered = array_values(array_filter($rows, static fn (array $row): bool => (string) ($row['status'] ?? '') === $status));
            usort($filtered, static fn (array $a, array $b): int => strcmp((string) ($a['last_seen_at'] ?? ''), (string) ($b['last_seen_at'] ?? '')));

            return array_slice($filtered, 0, max(1, $limit));
        });
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql, array $params = []) use (&$rows): string {
            $path = (string) ($params['path'] ?? '');

            return (string) (($rows[$path]['status'] ?? ''));
        });

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
