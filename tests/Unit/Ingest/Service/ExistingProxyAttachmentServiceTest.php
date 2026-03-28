<?php

namespace App\Tests\Unit\Ingest\Service;

use App\Asset\Repository\AssetRepositoryInterface;
use App\Derived\DerivedFile;
use App\Derived\DerivedFileRepositoryInterface;
use App\Entity\Asset;
use App\Ingest\Repository\IngestDiagnosticsRepository;
use App\Ingest\Service\ExistingProxyAttachmentService;
use App\Ingest\Service\WatchPathResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ExistingProxyAttachmentServiceTest extends TestCase
{
    public function testCanUseReturnsTrueWhenProxyFileExists(): void
    {
        $root = sys_get_temp_dir().'/retaia-existing-proxy-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        file_put_contents($root.'/INBOX/proxy.mp4', 'proxy-data');

        $service = new ExistingProxyAttachmentService(
            $this->watchPathResolver($root),
            $this->diagnosticsRepository(),
            $this->createMock(AssetRepositoryInterface::class),
            $this->createMock(DerivedFileRepositoryInterface::class),
        );

        self::assertTrue($service->canUse([
            'path' => 'INBOX/proxy.mp4',
            'type' => 'proxy_folder',
            'kind' => 'proxy_video',
            'original' => 'INBOX/original.mov',
        ], 'asset-1'));
    }

    public function testAttachToAssetMaterializesProxyAndUpdatesFields(): void
    {
        $root = sys_get_temp_dir().'/retaia-attach-proxy-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        file_put_contents($root.'/INBOX/proxy.jpg', 'proxy-data');

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

        $service = new ExistingProxyAttachmentService(
            $this->watchPathResolver($root),
            $this->diagnosticsRepository(),
            $assets,
            $derivedFiles,
        );

        $service->attachToAsset($asset, 'INBOX/original.cr2', [
            'path' => 'INBOX/proxy.jpg',
            'type' => 'raw_jpg',
            'kind' => 'proxy_photo',
            'original' => 'INBOX/original.cr2',
        ], 'nas-main');

        self::assertTrue((bool) ($asset->getFields()['proxy_done'] ?? false));
        self::assertContains('.derived/asset-1/proxy.jpg', $asset->getFields()['paths']['sidecars_relative'] ?? []);
        self::assertStringContainsString('/api/v1/assets/asset-1/derived/proxy_photo', (string) ($asset->getFields()['derived']['proxy_photo_url'] ?? ''));
        self::assertFileExists($root.'/.derived/asset-1/proxy.jpg');
        self::assertFileDoesNotExist($root.'/INBOX/proxy.jpg');
    }

    public function testCanUseFallsBackToExistingDerivedFile(): void
    {
        $root = sys_get_temp_dir().'/retaia-existing-derived-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/.derived/asset-1', 0777, true);
        file_put_contents($root.'/.derived/asset-1/proxy.mp4', 'proxy-data');

        $derivedFiles = $this->createMock(DerivedFileRepositoryInterface::class);
        $derivedFiles->expects(self::once())->method('findLatestByAssetAndKind')->with('asset-1', 'proxy_video')
            ->willReturn(new DerivedFile('d-1', 'asset-1', 'proxy_video', 'video/mp4', 10, 'hash', '.derived/asset-1/proxy.mp4', new \DateTimeImmutable()));

        $service = new ExistingProxyAttachmentService(
            $this->watchPathResolver($root),
            $this->diagnosticsRepository(),
            $this->createMock(AssetRepositoryInterface::class),
            $derivedFiles,
        );

        self::assertTrue($service->canUse([
            'path' => 'INBOX/missing.mp4',
            'type' => 'proxy_folder',
            'kind' => 'proxy_video',
            'original' => 'INBOX/original.mov',
        ], 'asset-1'));
    }

    private function watchPathResolver(string $root): WatchPathResolver
    {
        return new WatchPathResolver($root, 'INBOX');
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
}
