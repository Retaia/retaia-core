<?php

namespace App\Tests\Unit\Infrastructure\Asset;

use App\Asset\Repository\AssetRepositoryInterface;
use App\Derived\DerivedFileRepositoryInterface;
use App\Entity\Asset;
use App\Infrastructure\Asset\AssetCanonicalPathsProjector;
use App\Infrastructure\Asset\AssetDerivedViewProjector;
use App\Infrastructure\Asset\AssetFieldViewProjector;
use App\Infrastructure\Asset\AssetListView;
use App\Infrastructure\Asset\AssetReadGateway;
use App\Storage\BusinessStorageRegistryInterface;
use PHPUnit\Framework\TestCase;

final class AssetReadGatewayTest extends TestCase
{
    public function testGetByUuidReturnsNullWhenAssetIsMissing(): void
    {
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->method('findByUuid')->with('asset-1')->willReturn(null);
        $derived = new AssetDerivedViewProjector($this->createMock(DerivedFileRepositoryInterface::class));

        $gateway = new AssetReadGateway(
            $assets,
            new AssetCanonicalPathsProjector($this->createMock(BusinessStorageRegistryInterface::class)),
            $derived,
            new AssetFieldViewProjector(),
            new AssetListView($derived),
        );

        self::assertNull($gateway->getByUuid('asset-1'));
    }

    public function testListReturnsPaginatedPayload(): void
    {
        $asset = new Asset('asset-1', 'VIDEO', 'clip.mov', fields: [
            'paths' => [
                'storage_id' => 'nas-main',
                'original_relative' => 'INBOX/clip.mov',
            ],
        ]);
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->method('listAssets')->willReturn([$asset]);
        $storageRegistry = $this->createMock(BusinessStorageRegistryInterface::class);
        $storageRegistry->method('has')->willReturn(true);
        $derivedRepo = $this->createMock(DerivedFileRepositoryInterface::class);
        $derivedRepo->method('listByAsset')->willReturn([]);
        $derived = new AssetDerivedViewProjector($derivedRepo);
        $listView = new AssetListView($derived);

        $gateway = new AssetReadGateway(
            $assets,
            new AssetCanonicalPathsProjector($storageRegistry),
            $derived,
            new AssetFieldViewProjector(),
            $listView,
        );

        $result = $gateway->list([], null, null, null, null, null, 10, 0, [], 'AND', null, null, null, null);

        self::assertCount(1, $result['items'] ?? []);
        self::assertFalse($result['has_more'] ?? true);
    }
}
