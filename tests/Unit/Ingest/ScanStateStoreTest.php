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
        $storageId = 'nas-main';
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(static function (string $sql, array $params = []) use (&$rows): array|false {
            $storageId = (string) ($params['storageId'] ?? '');
            $path = (string) ($params['path'] ?? '');
            $key = $storageId.'|'.$path;

            return $rows[$key] ?? false;
        });
        $connection->method('insert')->willReturnCallback(static function (string $table, array $data) use (&$rows): int {
            if ($table === 'ingest_scan_file') {
                $rows[(string) $data['storage_id'].'|'.(string) $data['path']] = $data;
            }

            return 1;
        });
        $connection->method('update')->willReturnCallback(static function (string $table, array $data, array $criteria) use (&$rows): int {
            $storageId = (string) ($criteria['storage_id'] ?? '');
            $path = (string) ($criteria['path'] ?? '');
            $key = $storageId.'|'.$path;
            if ($table !== 'ingest_scan_file' || $storageId === '' || $path === '' || !isset($rows[$key])) {
                return 0;
            }

            $rows[$key] = array_merge($rows[$key], $data);

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
            $storageId = (string) ($params['storageId'] ?? '');
            $path = (string) ($params['path'] ?? '');
            $key = $storageId.'|'.$path;

            return (string) (($rows[$key]['status'] ?? ''));
        });

        $store = new ScanStateStore($connection);
        $mtime = new \DateTimeImmutable('2026-01-01 10:00:00');
        $first = $store->recordDetectedFile($storageId, 'INBOX/test.mov', 100, $mtime, new \DateTimeImmutable('2026-01-01 10:01:00'));
        $second = $store->recordDetectedFile($storageId, 'INBOX/test.mov', 100, $mtime, new \DateTimeImmutable('2026-01-01 10:02:00'));
        $third = $store->recordDetectedFile($storageId, 'INBOX/test.mov', 200, new \DateTimeImmutable('2026-01-01 10:03:00'), new \DateTimeImmutable('2026-01-01 10:03:00'));

        self::assertSame('discovered', $first['status']);
        self::assertSame(1, $first['stable_count']);
        self::assertSame('stable', $second['status']);
        self::assertSame(2, $second['stable_count']);
        self::assertSame('discovered', $third['status']);
        self::assertSame(1, $third['stable_count']);

        $stable = $store->listStableFiles();
        self::assertCount(0, $stable);

        $store->recordDetectedFile($storageId, 'INBOX/test.mov', 200, new \DateTimeImmutable('2026-01-01 10:03:00'), new \DateTimeImmutable('2026-01-01 10:04:00'));
        $stable = $store->listStableFiles();
        self::assertCount(1, $stable);
        self::assertSame($storageId, $stable[0]['storage_id']);
        self::assertSame('INBOX/test.mov', $stable[0]['path']);

        $store->markQueued($storageId, 'INBOX/test.mov', new \DateTimeImmutable('2026-01-01 10:05:00'));
        self::assertCount(0, $store->listStableFiles());

        $store->markMissing($storageId, 'INBOX/test.mov', new \DateTimeImmutable('2026-01-01 10:06:00'));
        $status = (string) $connection->fetchOne('SELECT status FROM ingest_scan_file WHERE storage_id = :storageId AND path = :path', ['storageId' => $storageId, 'path' => 'INBOX/test.mov']);
        self::assertSame('missing', $status);
    }
}
