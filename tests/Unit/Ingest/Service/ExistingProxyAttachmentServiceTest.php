<?php

namespace App\Tests\Unit\Ingest\Service;

use App\Asset\Repository\AssetRepositoryInterface;
use App\Derived\DerivedFile;
use App\Derived\DerivedFileRepositoryInterface;
use App\Entity\Asset;
use App\Ingest\Repository\IngestDiagnosticsRepository;
use App\Ingest\Service\ExistingProxyAttachmentService;
use App\Ingest\Service\ExistingProxyFilesystemInterface;
use App\Storage\BusinessStorageDefinition;
use App\Storage\BusinessStorageInterface;
use App\Storage\BusinessStorageRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ExistingProxyAttachmentServiceTest extends TestCase
{
    public function testCanUseReturnsTrueWhenProxyFileExists(): void
    {
        $filesystem = $this->createMock(ExistingProxyFilesystemInterface::class);
        $filesystem->expects(self::once())->method('isFile')->with(self::isInstanceOf(BusinessStorageInterface::class), 'INBOX/proxy.mp4')->willReturn(true);
        $filesystem->expects(self::once())->method('fileSize')->with(self::isInstanceOf(BusinessStorageInterface::class), 'INBOX/proxy.mp4')->willReturn(10);

        $service = new ExistingProxyAttachmentService(
            $this->storageRegistry(),
            $filesystem,
            $this->diagnosticsRepository(),
            $this->createMock(AssetRepositoryInterface::class),
            $this->createMock(DerivedFileRepositoryInterface::class),
        );

        self::assertTrue($service->canUse('nas-main', [
            'path' => 'INBOX/proxy.mp4',
            'type' => 'proxy_folder',
            'kind' => 'proxy_video',
            'original' => 'INBOX/original.mov',
        ], 'asset-1'));
    }

    public function testAttachToAssetMaterializesProxyAndUpdatesFields(): void
    {
        $asset = new Asset(
            uuid: 'asset-1',
            mediaType: 'PHOTO',
            filename: 'original.cr2',
            fields: [
                'paths' => [
                    'storage_id' => 'nas-main',
                    'original_relative' => 'INBOX/original.cr2',
                    'sidecars_relative' => ['INBOX/proxy.jpg'],
                ],
            ],
        );

        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->expects(self::once())->method('save')->with($asset);

        $derivedFiles = $this->createMock(DerivedFileRepositoryInterface::class);
        $derivedFiles->expects(self::once())->method('upsertMaterialized')->with(
            'asset-1',
            'proxy_photo',
            'image/jpeg',
            10,
            self::isType('string'),
            '.derived/asset-1/proxy.jpg',
        );

        $filesystem = $this->createMock(ExistingProxyFilesystemInterface::class);
        $filesystem->expects(self::once())
            ->method('materializeToDerived')
            ->with(self::isInstanceOf(BusinessStorageInterface::class), 'asset-1', 'proxy_photo', 'INBOX/proxy.jpg')
            ->willReturn('.derived/asset-1/proxy.jpg');
        $filesystem->expects(self::exactly(2))
            ->method('isFile')
            ->with(self::isInstanceOf(BusinessStorageInterface::class), '.derived/asset-1/proxy.jpg')
            ->willReturn(true);
        $filesystem->expects(self::once())
            ->method('fileSize')
            ->with(self::isInstanceOf(BusinessStorageInterface::class), '.derived/asset-1/proxy.jpg')
            ->willReturn(10);
        $filesystem->expects(self::once())
            ->method('hashSha256')
            ->with(self::isInstanceOf(BusinessStorageInterface::class), '.derived/asset-1/proxy.jpg')
            ->willReturn('hash');

        $service = new ExistingProxyAttachmentService(
            $this->storageRegistry(),
            $filesystem,
            $this->diagnosticsRepository(),
            $assets,
            $derivedFiles,
        );

        $service->attachToAsset($asset, 'nas-main', 'INBOX/original.cr2', [
            'path' => 'INBOX/proxy.jpg',
            'type' => 'raw_jpg',
            'kind' => 'proxy_photo',
            'original' => 'INBOX/original.cr2',
        ]);

        self::assertTrue((bool) ($asset->getFields()['proxy_done'] ?? false));
        self::assertSame([], $asset->getFields()['paths']['sidecars_relative'] ?? []);
        self::assertStringContainsString('/api/v1/assets/asset-1/derived/proxy_photo', (string) ($asset->getFields()['derived']['proxy_photo_url'] ?? ''));
    }

    public function testCanUseFallsBackToExistingDerivedFile(): void
    {
        $derivedFiles = $this->createMock(DerivedFileRepositoryInterface::class);
        $derivedFiles->expects(self::once())->method('findLatestByAssetAndKind')->with('asset-1', 'proxy_video')
            ->willReturn(new DerivedFile('d-1', 'asset-1', 'proxy_video', 'video/mp4', 10, 'hash', '.derived/asset-1/proxy.mp4', new \DateTimeImmutable()));

        $filesystem = $this->createMock(ExistingProxyFilesystemInterface::class);
        $filesystem->expects(self::exactly(2))
            ->method('isFile')
            ->willReturnCallback(static function (BusinessStorageInterface $storage, string $relativePath): bool {
                return match ($relativePath) {
                    'INBOX/missing.mp4' => false,
                    '.derived/asset-1/proxy.mp4' => true,
                    default => throw new \LogicException(sprintf('Unexpected path %s', $relativePath)),
                };
            });
        $filesystem->expects(self::once())->method('fileSize')->with(self::isInstanceOf(BusinessStorageInterface::class), '.derived/asset-1/proxy.mp4')->willReturn(10);

        $service = new ExistingProxyAttachmentService(
            $this->storageRegistry(),
            $filesystem,
            $this->diagnosticsRepository(),
            $this->createMock(AssetRepositoryInterface::class),
            $derivedFiles,
        );

        self::assertTrue($service->canUse('nas-main', [
            'path' => 'INBOX/missing.mp4',
            'type' => 'proxy_folder',
            'kind' => 'proxy_video',
            'original' => 'INBOX/original.mov',
        ], 'asset-1'));
    }
    private function diagnosticsRepository(): IngestDiagnosticsRepository
    {
        return new IngestDiagnosticsRepository($this->connection());
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement('CREATE TABLE ingest_unmatched_sidecar (path VARCHAR(255) PRIMARY KEY NOT NULL, reason VARCHAR(64) NOT NULL, detected_at DATETIME NOT NULL)');

        return $connection;
    }

    private function storageRegistry(): BusinessStorageRegistry
    {
        return new BusinessStorageRegistry('nas-main', [
            new BusinessStorageDefinition('nas-main', $this->createMock(BusinessStorageInterface::class), true),
        ]);
    }
}
