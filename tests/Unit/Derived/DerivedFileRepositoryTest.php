<?php

namespace App\Tests\Unit\Derived;

use App\Derived\DerivedFileRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class DerivedFileRepositoryTest extends TestCase
{
    public function testCreateListFindAndDeleteByAsset(): void
    {
        $repository = new DerivedFileRepository($this->connection());

        $created = $repository->create('asset-1', 'proxy', 'video/mp4', 456, 'hash');
        self::assertSame('asset-1', $created->assetUuid);
        self::assertSame('/derived/asset-1/'.$created->id, $created->storagePath);

        $list = $repository->listByAsset('asset-1');
        self::assertCount(1, $list);
        self::assertSame($created->id, $list[0]->id);

        $found = $repository->findLatestByAssetAndKind('asset-1', 'proxy');
        self::assertNotNull($found);
        self::assertSame($created->id, $found->id);
        self::assertSame([$created->storagePath], $repository->listStoragePathsByAsset('asset-1'));

        $repository->deleteByAsset('asset-1');
        self::assertSame([], $repository->listByAsset('asset-1'));
        self::assertNull($repository->findLatestByAssetAndKind('asset-1', 'proxy'));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement('CREATE TABLE asset_derived_file (id VARCHAR(16) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, kind VARCHAR(64) NOT NULL, content_type VARCHAR(128) NOT NULL, size_bytes INTEGER NOT NULL, sha256 VARCHAR(64) DEFAULT NULL, storage_path VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL)');

        return $connection;
    }
}
