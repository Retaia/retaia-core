<?php

namespace App\Tests\Unit\Ingest\Service;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Ingest\Repository\PathAuditRepository;
use App\Ingest\Service\IngestAssetPathUpdater;
use App\Ingest\Service\IngestDerivedOutboxMover;
use App\Ingest\Service\IngestOutboxMoveService;
use App\Storage\BusinessStorageDefinition;
use App\Storage\BusinessStorageInterface;
use App\Storage\BusinessStorageRegistryInterface;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class IngestOutboxMoveServiceTest extends TestCase
{
    public function testProcessAssetMovesOriginalAndDerivedFiles(): void
    {
        $asset = new Asset('asset-1', 'VIDEO', 'clip.mov', AssetState::ARCHIVED, [], null, [
            'paths' => [
                'storage_id' => 'nas-main',
                'original_relative' => 'INBOX/clip.mov',
            ],
        ]);

        $storage = $this->createMock(BusinessStorageInterface::class);
        $storage->method('fileExists')->willReturnMap([
            ['INBOX/clip.mov', true],
            ['ARCHIVE/clip.mov', false],
        ]);
        $storage->expects(self::once())->method('move')->with('INBOX/clip.mov', 'ARCHIVE/clip.mov');

        $registry = $this->createMock(BusinessStorageRegistryInterface::class);
        $registry->method('get')->with('nas-main')->willReturn(new BusinessStorageDefinition('nas-main', $storage));

        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->expects(self::once())->method('save')->with(self::callback(static function (Asset $saved): bool {
            $paths = $saved->getFields()['paths'] ?? [];

            return ($paths['original_relative'] ?? null) === 'ARCHIVE/clip.mov';
        }));

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE ingest_path_audit (id VARCHAR(32) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, from_path VARCHAR(255) NOT NULL, to_path VARCHAR(255) NOT NULL, reason VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL)');
        $connection->executeStatement('CREATE TABLE asset_derived_file (id VARCHAR(36) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, storage_path VARCHAR(255) NOT NULL)');

        $service = new IngestOutboxMoveService(
            $registry,
            $assets,
            new IngestAssetPathUpdater($assets, new PathAuditRepository($connection)),
            new IngestDerivedOutboxMover($connection),
        );

        self::assertSame(1, $service->processAsset($asset));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ingest_path_audit'));
    }
}
