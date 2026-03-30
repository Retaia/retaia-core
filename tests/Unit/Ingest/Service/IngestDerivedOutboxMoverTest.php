<?php

namespace App\Tests\Unit\Ingest\Service;

use App\Ingest\Service\IngestDerivedOutboxMover;
use App\Storage\BusinessStorageInterface;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class IngestDerivedOutboxMoverTest extends TestCase
{
    public function testMoveForAssetMovesDerivedFilesAndUpdatesPaths(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE asset_derived_file (id VARCHAR(32) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, storage_path VARCHAR(255) NOT NULL)');
        $connection->insert('asset_derived_file', ['id' => '1', 'asset_uuid' => 'asset-1', 'storage_path' => '.derived/asset-1/proxy.mp4']);

        $storage = $this->createMock(BusinessStorageInterface::class);
        $storage->method('fileExists')->willReturnMap([
            ['.derived/asset-1/proxy.mp4', true],
            ['ARCHIVE/.derived/asset-1/proxy.mp4', false],
        ]);
        $storage->expects(self::once())->method('move')->with('.derived/asset-1/proxy.mp4', 'ARCHIVE/.derived/asset-1/proxy.mp4');

        $mover = new IngestDerivedOutboxMover($connection);
        $remap = $mover->moveForAsset($storage, 'asset-1', 'ARCHIVE');

        self::assertSame(['.derived/asset-1/proxy.mp4' => 'ARCHIVE/.derived/asset-1/proxy.mp4'], $remap);
        self::assertSame('ARCHIVE/.derived/asset-1/proxy.mp4', $connection->fetchOne('SELECT storage_path FROM asset_derived_file WHERE id = ?', ['1']));
    }
}
