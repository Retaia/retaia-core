<?php

namespace App\Tests\Unit\Workflow;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Entity\Asset;
use App\Lock\Repository\OperationLockRepository;
use App\Workflow\Service\BatchDecisionCoordinator;
use PHPUnit\Framework\TestCase;

final class BatchDecisionCoordinatorTest extends TestCase
{
    public function testPreviewAndApplyDecisionsHandleEligibility(): void
    {
        $eligible = $this->asset('a-1', AssetState::DECISION_PENDING);
        $conflict = $this->asset('a-2', AssetState::PROCESSED);

        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->method('findByUuid')->willReturnCallback(static fn (string $uuid): ?Asset => match ($uuid) {
            'a-1' => $eligible,
            'a-2' => $conflict,
            default => null,
        });
        $assets->expects(self::once())->method('save')->with($eligible);

        $coordinator = new BatchDecisionCoordinator($assets, new AssetStateMachine(), $this->locks(false));
        $preview = $coordinator->previewDecisions(['a-1', 'a-2', 'a-3'], 'keep');
        $apply = $coordinator->applyDecisions(['a-1', 'a-2'], 'keep');

        self::assertSame(1, $preview['eligible_count']);
        self::assertSame(2, $preview['ineligible_count']);
        self::assertSame(1, $apply['applied_count']);
        self::assertSame('a-1', $apply['applied'][0]['uuid']);
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
}
