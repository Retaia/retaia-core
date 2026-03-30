<?php

namespace App\Tests\Unit\Infrastructure\Asset;

use App\Application\Asset\ReopenAssetResult;
use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Entity\Asset;
use App\Infrastructure\Asset\AssetWorkflowGateway;
use App\Lock\Repository\OperationLockRepository;
use PHPUnit\Framework\TestCase;

final class AssetWorkflowGatewayTest extends TestCase
{
    public function testReopenReturnsNotFoundWhenAssetIsMissing(): void
    {
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->method('findByUuid')->with('asset-1')->willReturn(null);

        $gateway = new AssetWorkflowGateway(
            $assets,
            new AssetStateMachine(),
            $this->createMock(OperationLockRepository::class),
        );

        self::assertSame(ReopenAssetResult::STATUS_NOT_FOUND, $gateway->reopen('asset-1')['status']);
    }

    public function testReprocessSavesAssetWhenTransitionSucceeds(): void
    {
        $asset = new Asset('asset-1', 'VIDEO', 'clip.mov', AssetState::READY, [], null, []);
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->method('findByUuid')->willReturn($asset);
        $assets->expects(self::once())->method('save')->with($asset);

        $locks = $this->createMock(OperationLockRepository::class);
        $locks->method('hasActiveLock')->willReturn(false);

        $gateway = new AssetWorkflowGateway($assets, new AssetStateMachine(), $locks);
        $result = $gateway->reprocess('asset-1');

        self::assertSame('REPROCESSED', $result['status']);
    }
}
