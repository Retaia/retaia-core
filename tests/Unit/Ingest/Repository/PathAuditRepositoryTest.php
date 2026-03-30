<?php

namespace App\Tests\Unit\Ingest\Repository;

use App\Ingest\Repository\PathAuditRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class PathAuditRepositoryTest extends TestCase
{
    public function testRecordInsertsAuditRow(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE ingest_path_audit (id VARCHAR(32) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, from_path VARCHAR(255) NOT NULL, to_path VARCHAR(255) NOT NULL, reason VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL)');

        $repository = new PathAuditRepository($connection);
        $repository->record('asset-1', 'INBOX/file.mov', 'ARCHIVE/file.mov', 'archive', new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ingest_path_audit'));
    }
}
