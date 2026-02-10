<?php

namespace App\Tests\Unit\Asset;

use App\Asset\AssetState;
use App\Asset\Service\AssetStateMachine;
use App\Asset\Service\StateConflictException;
use App\Entity\Asset;
use PHPUnit\Framework\TestCase;

final class AssetStateMachineTest extends TestCase
{
    public function testAllowsDecisionPendingToKeep(): void
    {
        $asset = new Asset('00000000-0000-0000-0000-000000000001', 'VIDEO', 'rush.mov', AssetState::DECISION_PENDING);

        $stateMachine = new AssetStateMachine();
        $stateMachine->decide($asset, 'KEEP');

        self::assertSame(AssetState::DECIDED_KEEP, $asset->getState());
    }

    public function testRejectsForbiddenTransition(): void
    {
        $asset = new Asset('00000000-0000-0000-0000-000000000002', 'VIDEO', 'rush.mov', AssetState::READY);

        $stateMachine = new AssetStateMachine();

        $this->expectException(StateConflictException::class);
        $stateMachine->transition($asset, AssetState::DECIDED_KEEP);
    }

    public function testPurgedIsTerminal(): void
    {
        $asset = new Asset('00000000-0000-0000-0000-000000000003', 'VIDEO', 'rush.mov', AssetState::PURGED);

        $stateMachine = new AssetStateMachine();

        $this->expectException(StateConflictException::class);
        $stateMachine->transition($asset, AssetState::READY);
    }
}
