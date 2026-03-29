<?php

namespace App\Tests\Unit\Infrastructure\Asset;

use App\Application\Asset\PatchAssetResult;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\AssetState;
use App\Asset\Service\AssetStateMachine;
use App\Entity\Asset;
use App\Infrastructure\Asset\AssetPatchGateway;
use App\Infrastructure\Asset\AssetPatchPayloadValidator;
use App\Infrastructure\Asset\AssetPatchStateApplier;
use App\Infrastructure\Asset\AssetPatchViewBuilder;
use App\Infrastructure\Asset\AssetProjectsNormalizer;
use App\Lock\Repository\OperationLockRepository;
use PHPUnit\Framework\TestCase;

final class AssetPatchGatewayTest extends TestCase
{
    public function testPatchReturnsNotFoundWhenAssetDoesNotExist(): void
    {
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->method('findByUuid')->with('asset-1')->willReturn(null);

        $gateway = $this->gateway($assets, $this->createMock(OperationLockRepository::class));

        self::assertSame([
            'status' => PatchAssetResult::STATUS_NOT_FOUND,
            'payload' => null,
        ], $gateway->patch('asset-1', ['notes' => 'x']));
    }

    public function testPatchRejectsWhenAssetHasActiveLock(): void
    {
        $asset = $this->asset();
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->method('findByUuid')->willReturn($asset);

        $locks = $this->createMock(OperationLockRepository::class);
        $locks->method('hasActiveLock')->with('asset-1')->willReturn(true);

        $gateway = $this->gateway($assets, $locks);

        self::assertSame(PatchAssetResult::STATUS_STATE_CONFLICT, $gateway->patch('asset-1', [])['status']);
    }

    public function testPatchAppliesValidatedPayloadAndSavesAsset(): void
    {
        $asset = $this->asset(state: AssetState::DECISION_PENDING);
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->method('findByUuid')->willReturn($asset);
        $assets->expects(self::once())->method('save')->with($asset);

        $locks = $this->createMock(OperationLockRepository::class);
        $locks->method('hasActiveLock')->willReturn(false);

        $gateway = $this->gateway($assets, $locks);
        $result = $gateway->patch('asset-1', [
            'tags' => ['news', 'news'],
            'notes' => 'Reviewed',
            'fields' => ['custom' => 'value'],
            'projects' => [[
                'project_id' => 'p1',
                'project_name' => 'Project',
                'created_at' => '2026-01-01T00:00:00Z',
            ]],
            'captured_at' => '2026-01-01T00:00:00Z',
            'state' => 'DECIDED_KEEP',
        ]);

        self::assertSame(PatchAssetResult::STATUS_PATCHED, $result['status']);
        self::assertSame(AssetState::DECIDED_KEEP, $asset->getState());
        self::assertSame(['news'], $asset->getTags());
        self::assertSame('Reviewed', $asset->getNotes());
        self::assertSame('value', $asset->getFields()['custom']);
        self::assertSame('2026-01-01T00:00:00Z', $asset->getFields()['captured_at']);
        self::assertArrayNotHasKey('projects', $result['payload']['fields']);
    }

    public function testPatchRejectsInvalidMetadataPayload(): void
    {
        $asset = $this->asset();
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->method('findByUuid')->willReturn($asset);
        $assets->expects(self::never())->method('save');

        $locks = $this->createMock(OperationLockRepository::class);
        $locks->method('hasActiveLock')->willReturn(false);

        $gateway = $this->gateway($assets, $locks);

        self::assertSame(PatchAssetResult::STATUS_VALIDATION_FAILED, $gateway->patch('asset-1', [
            'processing_profile' => 'bad-profile',
        ])['status']);
    }

    private function gateway(AssetRepositoryInterface $assets, OperationLockRepository $locks): AssetPatchGateway
    {
        $projects = new AssetProjectsNormalizer();

        return new AssetPatchGateway(
            $assets,
            $locks,
            new AssetPatchPayloadValidator(),
            $projects,
            new AssetPatchStateApplier(new AssetStateMachine()),
            new AssetPatchViewBuilder($projects),
        );
    }

    private function asset(AssetState $state = AssetState::READY): Asset
    {
        return new Asset('asset-1', 'video', 'clip.mp4', $state, [], null, []);
    }
}
