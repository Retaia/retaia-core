<?php

namespace App\Tests\Unit\Application\Job;

use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Job\FailJobHandler;
use App\Application\Job\JobEndpointActorContextResolver;
use App\Application\Job\JobEndpointFencingTokenParser;
use App\Application\Job\JobEndpointResult;
use App\Application\Job\JobFailEndpointHandler;
use App\Application\Job\ResolveJobLockConflictCodeHandler;
use App\Application\Job\Port\JobGateway;
use App\Job\Job;
use App\Job\JobStatus;
use PHPUnit\Framework\TestCase;

final class JobFailEndpointHandlerTest extends TestCase
{
    public function testReturnsValidationFailedWhenPayloadIsIncomplete(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $failHandler = new FailJobHandler($gateway, new ResolveJobLockConflictCodeHandler($gateway));

        $actorContext = $this->actorContextResolver('agent-1');

        $result = (new JobFailEndpointHandler($failHandler, $actorContext, new JobEndpointFencingTokenParser()))
            ->handle('job-1', ['lock_token' => 'tok', 'fencing_token' => 1, 'error_code' => 'X']);

        self::assertSame(JobEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testReturnsSuccessForFailedJob(): void
    {
        $job = new Job('job-1', 'asset-1', 'extract_facts', JobStatus::FAILED, 'agent-1', 'tok', null);
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::once())->method('fail')->willReturn($job);
        $failHandler = new FailJobHandler($gateway, new ResolveJobLockConflictCodeHandler($gateway));

        $actorContext = $this->actorContextResolver('agent-1');

        $result = (new JobFailEndpointHandler($failHandler, $actorContext, new JobEndpointFencingTokenParser()))
            ->handle('job-1', ['lock_token' => 'tok', 'fencing_token' => 1, 'error_code' => 'ERR', 'message' => 'boom', 'retryable' => true]);

        self::assertSame(JobEndpointResult::STATUS_SUCCESS, $result->status());
        self::assertSame('ERR', $result->errorCode());
        self::assertTrue($result->retryable());
    }

    private function actorContextResolver(string $id): JobEndpointActorContextResolver
    {
        $gateway = $this->createMock(AuthenticatedUserGateway::class);
        $gateway->method('currentUser')->willReturn(['id' => $id, 'email' => 'a@b.c', 'roles' => ['ROLE_AGENT']]);

        return new JobEndpointActorContextResolver(new ResolveAuthenticatedUserHandler($gateway));
    }
}
