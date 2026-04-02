<?php

namespace App\Tests\Unit\Workflow;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Asset\Service\StateConflictException;
use App\Entity\Asset;
use App\Lock\Repository\OperationLockRepository;
use App\Workflow\BatchMoveReportRepositoryInterface;
use App\Workflow\Service\BatchMoveCoordinator;
use PHPUnit\Framework\TestCase;

final class BatchMoveCoordinatorTest extends TestCase
{
    public function testPreviewMovesFiltersEligibleAssetsAndHandlesNameCollisions(): void
    {
        $assetKeep = $this->asset('a-1', 'same.mp4', AssetState::DECIDED_KEEP);
        $assetReject = $this->asset('a-2-abcdef', 'same.mp4', AssetState::DECIDED_REJECT);
        $assetIgnored = $this->asset('a-3', 'other.mp4', AssetState::READY);

        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->method('listAssets')->willReturn([$assetKeep, $assetReject, $assetIgnored]);

        $coordinator = new BatchMoveCoordinator($assets, new AssetStateMachine(), $this->locks(false), $this->batchMoveReports());
        $preview = $coordinator->previewMoves();

        self::assertSame(2, $preview['eligible_count']);
        self::assertSame('ARCHIVED', $preview['items'][0]['target_state']);
        self::assertSame('REJECTED', $preview['items'][1]['target_state']);
        self::assertNotSame($preview['items'][0]['target_filename'], $preview['items'][1]['target_filename']);
    }

    public function testApplyMovesReturnsSuccessesAndErrorsAndStoresReport(): void
    {
        $assetOk = $this->asset('a-ok', 'ok.mp4', AssetState::DECIDED_KEEP);
        $assetErr = $this->asset('a-err', 'err.mp4', AssetState::DECIDED_REJECT);

        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->method('listAssets')->willReturn([$assetOk, $assetErr]);
        $assets->expects(self::exactly(2))->method('save')->willReturnCallback(
            static function (Asset $asset): void {
                if ($asset->getUuid() === 'a-err') {
                    throw new StateConflictException('conflict');
                }
            }
        );

        $reports = $this->createMock(BatchMoveReportRepositoryInterface::class);
        $reports->expects(self::once())
            ->method('store')
            ->with(
                self::isType('string'),
                self::callback(static fn (array $payload): bool => isset($payload['batch_id'], $payload['success_count'], $payload['error_count']))
            );

        $coordinator = new BatchMoveCoordinator($assets, new AssetStateMachine(), $this->locks(false), $reports);
        $result = $coordinator->applyMoves();

        self::assertSame(1, $result['success_count']);
        self::assertSame(1, $result['error_count']);
        self::assertSame('a-ok', $result['successes'][0]['uuid']);
        self::assertSame('a-err', $result['errors'][0]['uuid']);
    }

    public function testGetBatchReportReadsStoredPayload(): void
    {
        $reports = $this->createMock(BatchMoveReportRepositoryInterface::class);
        $reports->expects(self::once())->method('find')->with('b-1')->willReturn(['batch_id' => 'b-1']);

        $coordinator = new BatchMoveCoordinator(
            $this->createMock(AssetRepositoryInterface::class),
            new AssetStateMachine(),
            $this->locks(false),
            $reports
        );

        self::assertSame(['batch_id' => 'b-1'], $coordinator->getBatchReport('b-1'));
    }

    private function asset(string $uuid, string $filename, AssetState $state): Asset
    {
        return new Asset(
            uuid: $uuid,
            mediaType: 'video',
            filename: $filename,
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

    private function batchMoveReports(): BatchMoveReportRepositoryInterface
    {
        return $this->createMock(BatchMoveReportRepositoryInterface::class);
    }
}
