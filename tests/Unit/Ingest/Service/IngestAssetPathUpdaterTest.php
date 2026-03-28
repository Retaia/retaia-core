<?php

namespace App\Tests\Unit\Ingest\Service;

use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Ingest\Repository\PathAuditRepository;
use App\Ingest\Service\IngestAssetPathUpdater;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class IngestAssetPathUpdaterTest extends TestCase
{
    public function testPersistPathUpdateWritesHistoryAndAudit(): void
    {
        $asset = new Asset(
            uuid: 'asset-1',
            mediaType: 'VIDEO',
            filename: 'clip.mov',
            fields: [
                'paths' => [
                    'storage_id' => 'nas-main',
                    'original_relative' => 'INBOX/clip.mov',
                    'sidecars_relative' => [],
                ],
            ],
        );

        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->expects(self::once())->method('save')->with($asset);

        $connection = $this->connection();
        $service = new IngestAssetPathUpdater(
            $assets,
            new PathAuditRepository($connection)
        );

        $service->persistPathUpdate($asset, 'INBOX/clip.mov', 'ARCHIVE/clip.mov');

        self::assertSame('ARCHIVE/clip.mov', $asset->getFields()['paths']['original_relative'] ?? null);
        self::assertCount(1, $asset->getFields()['path_history'] ?? []);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ingest_path_audit'));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement('CREATE TABLE ingest_path_audit (id VARCHAR(32) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, from_path VARCHAR(255) NOT NULL, to_path VARCHAR(255) NOT NULL, reason VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL)');

        return $connection;
    }
}
