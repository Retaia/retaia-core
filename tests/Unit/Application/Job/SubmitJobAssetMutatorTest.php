<?php

namespace App\Tests\Unit\Application\Job;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Application\Job\SubmitJobAssetMutator;
use App\Application\Job\SubmitJobDerivedPersister;
use App\Derived\DerivedFileRepositoryInterface;
use App\Entity\Asset;
use App\Job\Job;
use App\Job\JobStatus;
use PHPUnit\Framework\TestCase;

final class SubmitJobAssetMutatorTest extends TestCase
{
    public function testApplyUpdatesTranscriptWithoutDerivedWrites(): void
    {
        $asset = new Asset('asset-1', 'AUDIO', 'clip.wav', AssetState::READY);
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $derivedFiles = $this->createMock(DerivedFileRepositoryInterface::class);
        $derivedFiles->expects(self::never())->method('upsertMaterialized');
        $assets->expects(self::once())->method('findByUuid')->with('asset-1')->willReturn($asset);
        $assets->expects(self::once())->method('save')->with(self::callback(static function (Asset $saved): bool {
            return ($saved->getFields()['transcript']['text'] ?? null) === 'hello'
                && $saved->getState() === AssetState::PROCESSING_REVIEW;
        }));

        $mutator = new SubmitJobAssetMutator(
            $assets,
            new AssetStateMachine(),
            new SubmitJobDerivedPersister($derivedFiles),
        );

        $mutator->apply(
            new Job('job-1', 'asset-1', 'transcribe_audio', JobStatus::COMPLETED, 'agent-1', null, null, []),
            ['transcript_patch' => ['text' => 'hello']]
        );
    }

    public function testApplyReturnsWhenAssetIsMissing(): void
    {
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $derivedFiles = $this->createMock(DerivedFileRepositoryInterface::class);
        $assets->expects(self::once())->method('findByUuid')->with('asset-1')->willReturn(null);
        $assets->expects(self::never())->method('save');
        $derivedFiles->expects(self::never())->method('upsertMaterialized');

        $mutator = new SubmitJobAssetMutator(
            $assets,
            new AssetStateMachine(),
            new SubmitJobDerivedPersister($derivedFiles),
        );

        $mutator->apply(
            new Job('job-1', 'asset-1', 'generate_preview', JobStatus::COMPLETED, 'agent-1', null, null, []),
            ['derived_patch' => ['derived_manifest' => [['kind' => 'proxy_video', 'ref' => 'preview.mp4']]]]
        );
    }
}
