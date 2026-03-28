<?php

namespace App\Tests\Unit\Derived;

use App\Derived\DerivedUploadSessionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class DerivedUploadSessionRepositoryTest extends TestCase
{
    public function testCreateFindUpdateAndCompleteSession(): void
    {
        $repository = new DerivedUploadSessionRepository($this->connection());

        $created = $repository->create('asset-1', 'proxy', 'video/mp4', 123, 'hash');
        self::assertSame('asset-1', $created->assetUuid);
        self::assertTrue($created->isOpen());
        self::assertSame(0, $created->partsCount);

        $repository->updateHighestPartCount($created->uploadId, 3);
        $repository->updateHighestPartCount($created->uploadId, 2);
        $updated = $repository->find($created->uploadId);
        self::assertNotNull($updated);
        self::assertSame(3, $updated->partsCount);

        $repository->markCompleted($created->uploadId);
        $completed = $repository->find($created->uploadId);
        self::assertNotNull($completed);
        self::assertFalse($completed->isOpen());
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement('CREATE TABLE derived_upload_session (upload_id VARCHAR(24) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, kind VARCHAR(64) NOT NULL, content_type VARCHAR(128) NOT NULL, size_bytes INTEGER NOT NULL, sha256 VARCHAR(64) DEFAULT NULL, status VARCHAR(16) NOT NULL, parts_count INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');

        return $connection;
    }
}
