<?php

namespace App\Tests\Unit\Infrastructure\Asset;

use App\Application\Asset\PatchAssetResult;
use App\Asset\AssetState;
use App\Asset\Service\AssetStateMachine;
use App\Entity\Asset;
use App\Infrastructure\Asset\AssetPatchStateApplier;
use PHPUnit\Framework\TestCase;

final class AssetPatchStateApplierTest extends TestCase
{
    public function testApplyTransitionsSupportedStates(): void
    {
        $applier = new AssetPatchStateApplier(new AssetStateMachine());
        $asset = $this->asset(state: AssetState::DECISION_PENDING);

        self::assertSame(PatchAssetResult::STATUS_PATCHED, $applier->apply($asset, ['state' => 'decided_keep']));
        self::assertSame(AssetState::DECIDED_KEEP, $asset->getState());
    }

    public function testApplyRejectsInvalidOrConflictingStateChanges(): void
    {
        $applier = new AssetPatchStateApplier(new AssetStateMachine());

        self::assertSame(
            PatchAssetResult::STATUS_VALIDATION_FAILED,
            $applier->apply($this->asset(), ['state' => ['bad']])
        );
        self::assertSame(
            PatchAssetResult::STATUS_VALIDATION_FAILED,
            $applier->apply($this->asset(), ['state' => 'processing_review'])
        );
        self::assertSame(
            PatchAssetResult::STATUS_STATE_CONFLICT,
            $applier->apply($this->asset(state: AssetState::DISCOVERED), ['state' => 'ARCHIVED'])
        );
    }

    private function asset(AssetState $state = AssetState::READY): Asset
    {
        return new Asset('asset-1', 'video', 'clip.mp4', $state, [], null, []);
    }
}
