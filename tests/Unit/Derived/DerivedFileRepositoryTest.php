<?php

namespace App\Tests\Unit\Derived;

use App\Derived\DerivedFileRepository;
use App\Tests\Support\DerivedFileEntityManagerTrait;
use PHPUnit\Framework\TestCase;

final class DerivedFileRepositoryTest extends TestCase
{
    use DerivedFileEntityManagerTrait;

    public function testCreateListFindAndDeleteByAsset(): void
    {
        $repository = new DerivedFileRepository($this->derivedFileEntityManager());

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

    public function testUpsertMaterializedUpdatesExistingRecord(): void
    {
        $repository = new DerivedFileRepository($this->derivedFileEntityManager());
        $created = $repository->create('asset-2', 'proxy_video', 'video/mp4', 100, 'hash-a');

        $repository->upsertMaterialized('asset-2', 'proxy_video', 'video/mp4', 200, 'hash-b', '.derived/asset-2/proxy.mp4');

        $found = $repository->findLatestByAssetAndKind('asset-2', 'proxy_video');
        self::assertNotNull($found);
        self::assertSame($created->id, $found->id);
        self::assertSame('.derived/asset-2/proxy.mp4', $found->storagePath);
        self::assertSame(200, $found->sizeBytes);
        self::assertSame('hash-b', $found->sha256);
    }
}
