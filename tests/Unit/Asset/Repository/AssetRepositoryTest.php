<?php

namespace App\Tests\Unit\Asset\Repository;

use App\Asset\Repository\AssetRepository;
use App\Entity\Asset;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class AssetRepositoryTest extends TestCase
{
    public function testFindByUuidReturnsAssetOrNull(): void
    {
        $asset = new Asset('asset-1', 'VIDEO', 'clip.mov');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturnMap([
            [Asset::class, 'asset-1', null, null, $asset],
            [Asset::class, 'missing', null, null, null],
        ]);

        $repository = new AssetRepository($entityManager);

        self::assertSame($asset, $repository->findByUuid('asset-1'));
        self::assertNull($repository->findByUuid('missing'));
    }

    public function testSavePersistsAndFlushes(): void
    {
        $asset = new Asset('asset-1', 'VIDEO', 'clip.mov');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($asset);
        $entityManager->expects(self::once())->method('flush');

        $repository = new AssetRepository($entityManager);
        $repository->save($asset);
    }
}
