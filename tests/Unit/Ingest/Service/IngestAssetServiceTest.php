<?php

namespace App\Tests\Unit\Ingest\Service;

use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Ingest\Service\BusinessStorageAwareSidecarLocator;
use App\Ingest\Service\IngestAssetService;
use App\Ingest\Service\SidecarFileDetector;
use App\Storage\BusinessStorageRegistryInterface;
use App\Ingest\Repository\IngestDiagnosticsRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class IngestAssetServiceTest extends TestCase
{
    public function testFindOrCreateAssetBuildsCanonicalPaths(): void
    {
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->expects(self::once())->method('findByUuid')->willReturn(null);
        $assets->expects(self::once())->method('save')->with(self::isInstanceOf(Asset::class));

        $service = new IngestAssetService(
            $assets,
            $this->sidecarLocator(),
            new IngestDiagnosticsRepository($this->diagnosticsConnection()),
            new NullLogger()
        );

        $asset = $service->findOrCreateAsset('nas-main', 'INBOX/clip.mov');

        self::assertSame('VIDEO', $asset->getMediaType());
        self::assertSame('nas-main', $asset->getFields()['paths']['storage_id'] ?? null);
        self::assertSame('INBOX/clip.mov', $asset->getFields()['paths']['original_relative'] ?? null);
    }

    public function testAttachAuxiliarySidecarToAssetRejectsStorageMismatch(): void
    {
        $asset = new Asset(
            uuid: 'asset-1',
            mediaType: 'VIDEO',
            filename: 'clip.mov',
            fields: [
                'paths' => [
                    'storage_id' => 'nas-alt',
                    'original_relative' => 'INBOX/clip.mov',
                    'sidecars_relative' => [],
                ],
            ],
        );

        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->method('findByUuid')->willReturn($asset);
        $assets->expects(self::never())->method('save');

        $diagnosticsConnection = $this->diagnosticsConnection();
        $diagnostics = new IngestDiagnosticsRepository($diagnosticsConnection);

        $service = new IngestAssetService(
            $assets,
            $this->sidecarLocator(),
            $diagnostics,
            new NullLogger()
        );

        self::assertFalse($service->attachAuxiliarySidecarToAsset('nas-main', 'INBOX/clip.mov', 'INBOX/clip.srt'));
        self::assertSame(
            1,
            (int) $diagnosticsConnection->fetchOne('SELECT COUNT(*) FROM ingest_unmatched_sidecar WHERE path = :path AND reason = :reason', [
                'path' => 'INBOX/clip.srt',
                'reason' => 'storage_mismatch',
            ])
        );
    }

    private function sidecarLocator(): BusinessStorageAwareSidecarLocator
    {
        return new BusinessStorageAwareSidecarLocator(
            $this->createMock(BusinessStorageRegistryInterface::class),
            $this->createMock(SidecarFileDetector::class)
        );
    }

    private function diagnosticsConnection(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement('CREATE TABLE ingest_unmatched_sidecar (path VARCHAR(255) PRIMARY KEY NOT NULL, reason VARCHAR(64) NOT NULL, detected_at DATETIME NOT NULL)');

        return $connection;
    }
}
