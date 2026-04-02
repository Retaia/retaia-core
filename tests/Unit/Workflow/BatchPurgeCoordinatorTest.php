<?php

namespace App\Tests\Unit\Workflow;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Derived\DerivedFileRepositoryInterface;
use App\Entity\Asset;
use App\Lock\Repository\OperationLockRepository;
use App\Workflow\Service\AssetPurgeStorageService;
use App\Workflow\Service\BatchPurgeCoordinator;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class BatchPurgeCoordinatorTest extends TestCase
{
    public function testPreviewPurgeAndPurgePaths(): void
    {
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(0);

        $rejected = $this->asset('a-r', AssetState::REJECTED);
        $ready = $this->asset('a-ready', AssetState::READY);

        $assets->expects(self::once())->method('save')->with($rejected);

        $derivedFiles = $this->derivedFiles();
        $derivedFiles->expects(self::once())->method('listStoragePathsByAsset')->with('a-r')->willReturn([]);
        $derivedFiles->expects(self::once())->method('deleteByAsset')->with('a-r');

        $coordinator = new BatchPurgeCoordinator(
            $assets,
            new AssetStateMachine(),
            $connection,
            $this->locks(false),
            new AssetPurgeStorageService($derivedFiles)
        );

        self::assertTrue($coordinator->previewPurge($rejected)['allowed']);
        self::assertFalse($coordinator->previewPurge($ready)['allowed']);
        self::assertFalse($coordinator->purge($ready));
        self::assertTrue($coordinator->purge($rejected));
        self::assertSame(AssetState::PURGED, $rejected->getState());
    }

    private function asset(string $uuid, AssetState $state): Asset
    {
        return new Asset(
            uuid: $uuid,
            mediaType: 'video',
            filename: 'file.mp4',
            state: $state,
            tags: [],
            notes: null,
            fields: [],
            createdAt: new \DateTimeImmutable('-1 hour'),
            updatedAt: new \DateTimeImmutable('-1 hour'),
        );
    }

    private function locks(bool $hasActive): OperationLockRepository
    {
        $locks = $this->createMock(OperationLockRepository::class);
        $locks->method('hasActiveLock')->willReturn($hasActive);
        $locks->method('acquire')->willReturn(true);
        $locks->method('release');

        return $locks;
    }

    private function derivedFiles(): DerivedFileRepositoryInterface
    {
        $derivedFiles = $this->createMock(DerivedFileRepositoryInterface::class);
        $derivedFiles->method('listStoragePathsByAsset')->willReturn([]);

        return $derivedFiles;
    }
}
