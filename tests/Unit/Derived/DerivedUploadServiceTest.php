<?php

namespace App\Tests\Unit\Derived;

use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Derived\DerivedFile;
use App\Derived\DerivedFileRepositoryInterface;
use App\Derived\DerivedUploadSession;
use App\Derived\DerivedUploadSessionRepositoryInterface;
use App\Derived\Service\DerivedUploadService;
use PHPUnit\Framework\TestCase;

final class DerivedUploadServiceTest extends TestCase
{
    public function testInitCreatesOpenUploadSession(): void
    {
        $sessions = $this->createMock(DerivedUploadSessionRepositoryInterface::class);
        $files = $this->createMock(DerivedFileRepositoryInterface::class);
        $assets = $this->assetRepositoryWithStorage('asset-1', 'nas-main');
        $sessions->expects(self::once())->method('create')->with('asset-1', 'proxy', 'video/mp4', 1024, null)
            ->willReturn(new DerivedUploadSession('upload-1', 'asset-1', 'proxy', 'video/mp4', 1024, null, 'open', 0));

        $service = new DerivedUploadService($sessions, $files, $assets);
        $result = $service->init('asset-1', 'proxy', 'video/mp4', 1024, null);

        self::assertSame('open', $result['status']);
        self::assertSame(5 * 1024 * 1024, $result['part_size_bytes']);
        self::assertSame('upload-1', $result['upload_id']);
    }

    public function testAddPartRejectsInvalidOrClosedSession(): void
    {
        $sessions = $this->createMock(DerivedUploadSessionRepositoryInterface::class);
        $files = $this->createMock(DerivedFileRepositoryInterface::class);
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $sessions->expects(self::once())->method('find')->with('up-1')
            ->willReturn(new DerivedUploadSession('up-1', 'asset-1', 'proxy', 'video/mp4', 1, null, 'completed', 1));
        $sessions->expects(self::never())->method('updateHighestPartCount');

        $service = new DerivedUploadService($sessions, $files, $assets);

        self::assertFalse($service->addPart('up-1', 1));
    }

    public function testAddPartUpdatesHighestPartCount(): void
    {
        $sessions = $this->createMock(DerivedUploadSessionRepositoryInterface::class);
        $files = $this->createMock(DerivedFileRepositoryInterface::class);
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $sessions->expects(self::once())->method('find')->with('up-2')
            ->willReturn(new DerivedUploadSession('up-2', 'asset-2', 'proxy', 'video/mp4', 1, null, 'open', 2));
        $sessions->expects(self::once())->method('updateHighestPartCount')->with('up-2', 5);

        $service = new DerivedUploadService($sessions, $files, $assets);

        self::assertTrue($service->addPart('up-2', 5));
    }

    public function testCompleteReturnsNullWhenSessionCannotBeCompleted(): void
    {
        $sessions = $this->createMock(DerivedUploadSessionRepositoryInterface::class);
        $files = $this->createMock(DerivedFileRepositoryInterface::class);
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $sessions->expects(self::exactly(4))->method('find')
            ->willReturnOnConsecutiveCalls(
                null,
                new DerivedUploadSession('up-2', 'asset-1', 'proxy', 'video/mp4', 1, null, 'completed', 1),
                new DerivedUploadSession('up-3', 'other', 'proxy', 'video/mp4', 1, null, 'open', 5),
                new DerivedUploadSession('up-4', 'asset-1', 'proxy', 'video/mp4', 1, null, 'open', 0),
            );
        $files->expects(self::never())->method('create');

        $service = new DerivedUploadService($sessions, $files, $assets);

        self::assertNull($service->complete('asset-1', 'up-1', 1));
        self::assertNull($service->complete('asset-1', 'up-2', 1));
        self::assertNull($service->complete('asset-1', 'up-3', 1));
        self::assertNull($service->complete('asset-1', 'up-4', 1));
    }

    public function testCompleteCreatesDerivedFileAndMarksSessionCompleted(): void
    {
        $sessions = $this->createMock(DerivedUploadSessionRepositoryInterface::class);
        $files = $this->createMock(DerivedFileRepositoryInterface::class);
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $sessions->expects(self::once())->method('find')->with('up-4')
            ->willReturn(new DerivedUploadSession('up-4', 'asset-4', 'proxy', 'video/mp4', 2000, 'hash', 'open', 2));
        $files->expects(self::once())->method('create')->with('asset-4', 'proxy', 'video/mp4', 2000, 'hash')
            ->willReturn(new DerivedFile('d-4', 'asset-4', 'proxy', 'video/mp4', 2000, 'hash', '/derived/asset-4/d-4', new \DateTimeImmutable('2026-01-01T10:00:00+00:00')));
        $sessions->expects(self::once())->method('markCompleted')->with('up-4');

        $service = new DerivedUploadService($sessions, $files, $assets);
        $result = $service->complete('asset-4', 'up-4', 2);

        self::assertIsArray($result);
        self::assertSame('asset-4', $result['asset_uuid']);
        self::assertSame('proxy', $result['kind']);
        self::assertSame('/api/v1/assets/asset-4/derived/proxy', $result['url']);
    }

    public function testListAndFindNormalizeRows(): void
    {
        $sessions = $this->createMock(DerivedUploadSessionRepositoryInterface::class);
        $files = $this->createMock(DerivedFileRepositoryInterface::class);
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $files->expects(self::once())->method('listByAsset')->with('asset-7')
            ->willReturn([
                new DerivedFile('d-1', 'asset-7', 'proxy', 'video/mp4', 77, 'hash', '/tmp/x', new \DateTimeImmutable('2026-01-01T10:00:00+00:00')),
            ]);
        $files->expects(self::exactly(2))->method('findLatestByAssetAndKind')
            ->willReturnOnConsecutiveCalls(
                new DerivedFile('d-2', 'asset-7', 'thumbnail', 'image/png', 10, null, '/tmp/y', new \DateTimeImmutable('2026-01-01T10:05:00+00:00')),
                null,
            );

        $service = new DerivedUploadService($sessions, $files, $assets);
        $list = $service->listForAsset('asset-7');
        $found = $service->findByAssetAndKind('asset-7', 'thumbnail');
        $notFound = $service->findByAssetAndKind('asset-7', 'missing');

        self::assertCount(1, $list);
        self::assertSame('/api/v1/assets/asset-7/derived/proxy', $list[0]['url']);
        self::assertSame('thumbnail', $found['kind']);
        self::assertNull($notFound);
    }

    private function assetRepositoryWithStorage(string $assetUuid, string $storageId): AssetRepositoryInterface
    {
        $asset = new Asset(
            uuid: $assetUuid,
            mediaType: 'VIDEO',
            filename: 'source.mov',
            fields: [
                'paths' => [
                    'storage_id' => $storageId,
                    'original_relative' => 'INBOX/source.mov',
                    'sidecars_relative' => [],
                ],
            ],
        );

        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->expects(self::once())->method('findByUuid')->with($assetUuid)->willReturn($asset);

        return $assets;
    }
}
