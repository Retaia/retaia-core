<?php

namespace App\Tests\Unit\Application\Job;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Application\Job\CheckSuggestTagsSubmitScopeHandler;
use App\Application\Job\Port\JobGateway;
use App\Application\Job\ResolveJobLockConflictCodeHandler;
use App\Application\Job\SubmitJobHandler;
use App\Application\Job\SubmitJobResult;
use App\Derived\DerivedFileRepositoryInterface;
use App\Entity\Asset;
use App\Job\Job;
use App\Job\JobStatus;
use PHPUnit\Framework\TestCase;

final class SubmitJobHandlerTest extends TestCase
{
    public function testHandleReturnsForbiddenScopeForSuggestTagsWithoutScope(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $derivedFiles = $this->createMock(DerivedFileRepositoryInterface::class);
        $stateMachine = new AssetStateMachine();
        $gateway->expects(self::once())->method('find')->with('job-1')->willReturn(
            new Job('job-1', 'asset-1', 'suggest_tags', JobStatus::CLAIMED, 'agent-1', 't', null, [])
        );
        $gateway->expects(self::never())->method('submit');

        $handler = new SubmitJobHandler(
            $gateway,
            $assets,
            $derivedFiles,
            $stateMachine,
            new CheckSuggestTagsSubmitScopeHandler(true),
            new ResolveJobLockConflictCodeHandler($gateway)
        );

        $result = $handler->handle('job-1', 'agent-1', 't', 1, 'suggest_tags', ['ok' => true], ['ROLE_AGENT']);

        self::assertSame(SubmitJobResult::STATUS_FORBIDDEN_SCOPE, $result->status());
    }

    public function testHandleReturnsLockConflictStatusesWhenSubmitFails(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $derivedFiles = $this->createMock(DerivedFileRepositoryInterface::class);
        $assets->method('findByUuid')->willReturn(null);
        $stateMachine = new AssetStateMachine();
        $gateway->expects(self::exactly(4))->method('find')->with('job-1')->willReturnOnConsecutiveCalls(
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::CLAIMED, 'agent-1', 'right-token', null, []),
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::CLAIMED, 'agent-1', 'right-token', null, []),
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::PENDING, null, null, null, []),
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::PENDING, null, null, null, [])
        );
        $gateway->expects(self::exactly(2))->method('submit')->with('job-1', 'agent-1', 'wrong-token', 1, ['facts_patch' => ['duration_ms' => 42]])->willReturn(null);

        $handler = new SubmitJobHandler(
            $gateway,
            $assets,
            $derivedFiles,
            $stateMachine,
            new CheckSuggestTagsSubmitScopeHandler(true),
            new ResolveJobLockConflictCodeHandler($gateway)
        );

        self::assertSame(SubmitJobResult::STATUS_STALE_LOCK_TOKEN, $handler->handle('job-1', 'agent-1', 'wrong-token', 1, 'extract_facts', ['facts_patch' => ['duration_ms' => 42]], ['ROLE_AGENT'])->status());
        self::assertSame(SubmitJobResult::STATUS_LOCK_INVALID, $handler->handle('job-1', 'agent-1', 'wrong-token', 1, 'extract_facts', ['facts_patch' => ['duration_ms' => 42]], ['ROLE_AGENT'])->status());
    }

    public function testHandleReturnsSubmittedWhenGatewaySucceeds(): void
    {
        $job = new Job('job-1', 'asset-1', 'extract_facts', JobStatus::COMPLETED, 'agent-1', null, null, ['ok' => true]);

        $gateway = $this->createMock(JobGateway::class);
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $derivedFiles = $this->createMock(DerivedFileRepositoryInterface::class);
        $assets->method('findByUuid')->willReturn(null);
        $stateMachine = new AssetStateMachine();
        $gateway->expects(self::once())->method('find')->with('job-1')->willReturn(
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::CLAIMED, 'agent-1', 'token', null, [])
        );
        $gateway->expects(self::once())->method('submit')->with('job-1', 'agent-1', 'token', 1, ['facts_patch' => ['duration_ms' => 42]])->willReturn($job);

        $handler = new SubmitJobHandler(
            $gateway,
            $assets,
            $derivedFiles,
            $stateMachine,
            new CheckSuggestTagsSubmitScopeHandler(true),
            new ResolveJobLockConflictCodeHandler($gateway)
        );

        $result = $handler->handle('job-1', 'agent-1', 'token', 1, 'extract_facts', ['facts_patch' => ['duration_ms' => 42]], ['ROLE_SUGGESTIONS_WRITE']);

        self::assertSame(SubmitJobResult::STATUS_SUBMITTED, $result->status());
        self::assertSame($job, $result->job());
    }

    public function testHandleReturnsValidationFailedWhenJobTypeDoesNotMatchJob(): void
    {
        $this->assertValidationFailedResult(
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::CLAIMED, 'agent-1', 'token', null, []),
            'generate_preview',
            ['derived_patch' => ['derived_manifest' => []]],
            ['ROLE_AGENT']
        );
    }

    public function testHandleReturnsValidationFailedForDomainOwnershipViolation(): void
    {
        $this->assertValidationFailedResult(
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::CLAIMED, 'agent-1', 'token', null, []),
            'extract_facts',
            ['derived_patch' => ['derived_manifest' => []]],
            ['ROLE_AGENT']
        );
    }

    public function testHandleReturnsValidationFailedForUnknownResultKey(): void
    {
        $this->assertValidationFailedResult(
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::CLAIMED, 'agent-1', 'token', null, []),
            'extract_facts',
            ['unexpected' => true],
            ['ROLE_AGENT']
        );
    }

    public function testHandleReturnsValidationFailedForInvalidDerivedManifest(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $derivedFiles = $this->createMock(DerivedFileRepositoryInterface::class);
        $stateMachine = new AssetStateMachine();
        $gateway->expects(self::once())->method('find')->with('job-1')->willReturn(
            new Job('job-1', 'asset-1', 'generate_preview', JobStatus::CLAIMED, 'agent-1', 'token', null, [])
        );
        $gateway->expects(self::never())->method('submit');

        $handler = new SubmitJobHandler(
            $gateway,
            $assets,
            $derivedFiles,
            $stateMachine,
            new CheckSuggestTagsSubmitScopeHandler(true),
            new ResolveJobLockConflictCodeHandler($gateway)
        );

        $result = $handler->handle('job-1', 'agent-1', 'token', 1, 'generate_preview', [
            'derived_patch' => ['derived_manifest' => [['kind' => 'unknown', 'ref' => 'x']]],
        ], ['ROLE_AGENT']);

        self::assertSame(SubmitJobResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testHandleAppliesExtractFactsPatchToAssetAndMovesToProcessingReview(): void
    {
        $asset = new Asset('asset-1', 'VIDEO', 'rush.mov', AssetState::READY);

        $gateway = $this->createMock(JobGateway::class);
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $derivedFiles = $this->createMock(DerivedFileRepositoryInterface::class);
        $stateMachine = new AssetStateMachine();

        $gateway->expects(self::once())->method('find')->with('job-1')->willReturn(
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::CLAIMED, 'agent-1', 'token', null, [])
        );
        $gateway->expects(self::once())->method('submit')->willReturn(
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::COMPLETED, 'agent-1', null, null, [])
        );
        $assets->expects(self::once())->method('findByUuid')->with('asset-1')->willReturn($asset);
        $assets->expects(self::once())->method('save')->with(self::callback(function (Asset $saved): bool {
            $fields = $saved->getFields();

            return $saved->getState() === AssetState::PROCESSING_REVIEW
                && ($fields['facts']['duration_ms'] ?? null) === 1200
                && (bool) ($fields['facts_done'] ?? false);
        }));

        $handler = new SubmitJobHandler(
            $gateway,
            $assets,
            $derivedFiles,
            $stateMachine,
            new CheckSuggestTagsSubmitScopeHandler(true),
            new ResolveJobLockConflictCodeHandler($gateway)
        );

        $result = $handler->handle('job-1', 'agent-1', 'token', 1, 'extract_facts', ['facts_patch' => ['duration_ms' => 1200]], ['ROLE_AGENT']);

        self::assertSame(SubmitJobResult::STATUS_SUBMITTED, $result->status());
    }

    public function testHandleAppliesDerivedPatchAndMovesToDecisionPendingWhenProfileComplete(): void
    {
        $asset = new Asset(
            'asset-1',
            'VIDEO',
            'rush.mov',
            AssetState::PROCESSING_REVIEW,
            [],
            null,
            ['facts_done' => true, 'proxy_done' => true]
        );

        $gateway = $this->createMock(JobGateway::class);
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $derivedFiles = $this->createMock(DerivedFileRepositoryInterface::class);
        $stateMachine = new AssetStateMachine();

        $gateway->expects(self::once())->method('find')->with('job-1')->willReturn(
            new Job('job-1', 'asset-1', 'generate_thumbnails', JobStatus::CLAIMED, 'agent-1', 'token', null, [])
        );
        $gateway->expects(self::once())->method('submit')->willReturn(
            new Job('job-1', 'asset-1', 'generate_thumbnails', JobStatus::COMPLETED, 'agent-1', null, null, [])
        );
        $assets->expects(self::once())->method('findByUuid')->with('asset-1')->willReturn($asset);
        $derivedFiles->expects(self::once())->method('upsertMaterialized')->with(
            'asset-1',
            'thumb',
            'image/jpeg',
            0,
            null,
            'thumb:1',
        );
        $assets->expects(self::once())->method('save')->with(self::callback(function (Asset $saved): bool {
            $fields = $saved->getFields();

            return $saved->getState() === AssetState::DECISION_PENDING
                && (bool) ($fields['thumbs_done'] ?? false)
                && !isset($fields['derived']['derived_manifest']);
        }));

        $handler = new SubmitJobHandler(
            $gateway,
            $assets,
            $derivedFiles,
            $stateMachine,
            new CheckSuggestTagsSubmitScopeHandler(true),
            new ResolveJobLockConflictCodeHandler($gateway)
        );

        $result = $handler->handle('job-1', 'agent-1', 'token', 1, 'generate_thumbnails', [
            'derived_patch' => [
                'derived_manifest' => [
                    ['kind' => 'thumb', 'ref' => 'thumb:1'],
                ],
            ],
        ], ['ROLE_AGENT']);

        self::assertSame(SubmitJobResult::STATUS_SUBMITTED, $result->status());
    }

    private function assertValidationFailedResult(
        Job $claimedJob,
        string $jobType,
        array $resultPayload,
        array $actorRoles
    ): void {
        $gateway = $this->createMock(JobGateway::class);
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $derivedFiles = $this->createMock(DerivedFileRepositoryInterface::class);
        $stateMachine = new AssetStateMachine();
        $gateway->expects(self::once())->method('find')->with('job-1')->willReturn($claimedJob);
        $gateway->expects(self::never())->method('submit');

        $handler = new SubmitJobHandler(
            $gateway,
            $assets,
            $derivedFiles,
            $stateMachine,
            new CheckSuggestTagsSubmitScopeHandler(true),
            new ResolveJobLockConflictCodeHandler($gateway)
        );

        $result = $handler->handle('job-1', 'agent-1', 'token', 1, $jobType, $resultPayload, $actorRoles);

        self::assertSame(SubmitJobResult::STATUS_VALIDATION_FAILED, $result->status());
    }
}
