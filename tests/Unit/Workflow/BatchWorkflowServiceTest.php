<?php

namespace App\Tests\Unit\Workflow;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Asset\Service\StateConflictException;
use App\Entity\Asset;
use App\Workflow\Service\BatchWorkflowService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class BatchWorkflowServiceTest extends TestCase
{
    public function testPreviewMovesFiltersEligibleAssetsAndHandlesNameCollisions(): void
    {
        $assetKeep = $this->asset('a-1', 'same.mp4', AssetState::DECIDED_KEEP);
        $assetReject = $this->asset('a-2-abcdef', 'same.mp4', AssetState::DECIDED_REJECT);
        $assetIgnored = $this->asset('a-3', 'other.mp4', AssetState::READY);

        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->method('listAssets')->willReturn([$assetKeep, $assetReject, $assetIgnored]);

        $service = new BatchWorkflowService($assets, new AssetStateMachine(), $this->createMock(Connection::class));
        $preview = $service->previewMoves();

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

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->with(
            'batch_move_report',
            self::callback(static fn (array $payload): bool => isset($payload['batch_id'], $payload['payload']))
        );

        $service = new BatchWorkflowService($assets, new AssetStateMachine(), $connection);
        $result = $service->applyMoves();

        self::assertSame(1, $result['success_count']);
        self::assertSame(1, $result['error_count']);
        self::assertSame('a-ok', $result['successes'][0]['uuid']);
        self::assertSame('a-err', $result['errors'][0]['uuid']);
    }

    public function testPreviewAndApplyDecisionsHandleEligibility(): void
    {
        $eligible = $this->asset('a-1', 'a1.mp4', AssetState::DECISION_PENDING);
        $conflict = $this->asset('a-2', 'a2.mp4', AssetState::PROCESSED);

        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->method('findByUuid')->willReturnCallback(static fn (string $uuid): ?Asset => match ($uuid) {
            'a-1' => $eligible,
            'a-2' => $conflict,
            default => null,
        });
        $assets->expects(self::once())->method('save')->with($eligible);

        $service = new BatchWorkflowService($assets, new AssetStateMachine(), $this->createMock(Connection::class));
        $preview = $service->previewDecisions(['a-1', 'a-2', 'a-3'], 'keep');
        $apply = $service->applyDecisions(['a-1', 'a-2'], 'keep');

        self::assertSame(1, $preview['eligible_count']);
        self::assertSame(2, $preview['ineligible_count']);
        self::assertSame(1, $apply['applied_count']);
        self::assertSame('a-1', $apply['applied'][0]['uuid']);
    }

    public function testGetBatchReportAndPurgePaths(): void
    {
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $connection = $this->createMock(Connection::class);

        $connection->expects(self::exactly(3))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                ['payload' => '{"batch_id":"b-1"}'],
                ['payload' => 'not-json'],
                false
            );

        $rejected = $this->asset('a-r', 'r.mp4', AssetState::REJECTED);
        $ready = $this->asset('a-ready', 'ready.mp4', AssetState::READY);

        $assets->expects(self::once())->method('save')->with($rejected);

        $service = new BatchWorkflowService($assets, new AssetStateMachine(), $connection);

        self::assertSame(['batch_id' => 'b-1'], $service->getBatchReport('b-1'));
        self::assertNull($service->getBatchReport('b-2'));
        self::assertNull($service->getBatchReport('b-3'));
        self::assertTrue($service->previewPurge($rejected)['allowed']);
        self::assertFalse($service->previewPurge($ready)['allowed']);
        self::assertFalse($service->purge($ready));
        self::assertTrue($service->purge($rejected));
        self::assertSame(AssetState::PURGED, $rejected->getState());
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
}
