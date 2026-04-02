<?php

namespace App\Tests\Unit\Application\Job;

use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Job\JobEndpointActorContextResolver;
use App\Application\Job\JobEndpointFencingTokenParser;
use App\Application\Job\JobEndpointResult;
use App\Application\Job\JobSubmitEndpointHandler;
use App\Application\Job\CheckSuggestTagsSubmitScopeHandler;
use App\Application\Job\ResolveJobLockConflictCodeHandler;
use App\Application\Job\SubmitJobAssetMutator;
use App\Application\Job\SubmitJobHandler;
use App\Application\Job\SubmitJobDerivedPersister;
use App\Application\Job\SubmitJobResultValidator;
use App\Application\Job\Port\JobGateway;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Derived\DerivedFileRepositoryInterface;
use App\Job\Job;
use App\Job\JobStatus;
use PHPUnit\Framework\TestCase;

final class JobSubmitEndpointHandlerTest extends TestCase
{
    public function testReturnsValidationFailedForUnsupportedJobType(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $submitHandler = $this->submitHandler($gateway);

        $actorContext = $this->actorContextResolver('agent-1', ['ROLE_AGENT']);

        $result = (new JobSubmitEndpointHandler($submitHandler, $actorContext, new JobEndpointFencingTokenParser()))
            ->handle('job-1', ['lock_token' => 'tok', 'fencing_token' => 1, 'job_type' => 'suggest_tags']);

        self::assertSame(JobEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testReturnsSuccessForSubmittedJob(): void
    {
        $job = new Job('job-1', 'asset-1', 'extract_facts', JobStatus::COMPLETED, 'agent-1', 'tok', null);
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::once())->method('submit')->willReturn($job);
        $submitHandler = $this->submitHandler($gateway);

        $actorContext = $this->actorContextResolver('agent-1', ['ROLE_AGENT']);

        $result = (new JobSubmitEndpointHandler($submitHandler, $actorContext, new JobEndpointFencingTokenParser()))
            ->handle('job-1', ['lock_token' => 'tok', 'fencing_token' => 1, 'job_type' => 'extract_facts', 'result' => ['facts_patch' => ['duration_ms' => 1200]]]);

        self::assertSame(JobEndpointResult::STATUS_SUCCESS, $result->status());
        self::assertInstanceOf(Job::class, $result->job());
    }

    private function submitHandler(JobGateway $gateway): SubmitJobHandler
    {
        return new SubmitJobHandler(
            $gateway,
            new SubmitJobAssetMutator(
                $this->createMock(AssetRepositoryInterface::class),
                new AssetStateMachine(),
                new SubmitJobDerivedPersister($this->createMock(DerivedFileRepositoryInterface::class)),
            ),
            new SubmitJobResultValidator(),
            new CheckSuggestTagsSubmitScopeHandler(true),
            new ResolveJobLockConflictCodeHandler($gateway)
        );
    }

    /**
     * @param array<int, string> $roles
     */
    private function actorContextResolver(string $id, array $roles): JobEndpointActorContextResolver
    {
        $gateway = $this->createMock(AuthenticatedUserGateway::class);
        $gateway->method('currentUser')->willReturn(['id' => $id, 'email' => 'a@b.c', 'roles' => $roles]);

        return new JobEndpointActorContextResolver(new ResolveAuthenticatedUserHandler($gateway));
    }
}
