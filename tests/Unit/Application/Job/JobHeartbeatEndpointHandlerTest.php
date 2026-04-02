<?php

namespace App\Tests\Unit\Application\Job;

use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Job\HeartbeatJobHandler;
use App\Application\Job\JobEndpointActorContextResolver;
use App\Application\Job\JobEndpointFencingTokenParser;
use App\Application\Job\JobEndpointResult;
use App\Application\Job\JobHeartbeatEndpointHandler;
use App\Application\Job\ResolveJobLockConflictCodeHandler;
use App\Application\Job\Port\JobGateway;
use App\Job\Job;
use App\Job\JobStatus;
use PHPUnit\Framework\TestCase;

final class JobHeartbeatEndpointHandlerTest extends TestCase
{
    public function testReturnsValidationFailedForInvalidFencingToken(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $heartbeatHandler = new HeartbeatJobHandler($gateway, new ResolveJobLockConflictCodeHandler($gateway));

        $actorContext = $this->actorContextResolver('agent-1');

        $result = (new JobHeartbeatEndpointHandler($heartbeatHandler, $actorContext, new JobEndpointFencingTokenParser()))
            ->handle('job-1', ['lock_token' => 'tok', 'fencing_token' => 'x']);

        self::assertSame(JobEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testReturnsSuccessForHeartbeatedJob(): void
    {
        $job = new Job('job-1', 'asset-1', 'extract_facts', JobStatus::CLAIMED, 'agent-1', 'tok', new \DateTimeImmutable('2026-04-02T12:00:00+00:00'), [], [], null, 7);
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::once())->method('heartbeat')->willReturn($job);
        $heartbeatHandler = new HeartbeatJobHandler($gateway, new ResolveJobLockConflictCodeHandler($gateway));

        $actorContext = $this->actorContextResolver('agent-1');

        $result = (new JobHeartbeatEndpointHandler($heartbeatHandler, $actorContext, new JobEndpointFencingTokenParser()))
            ->handle('job-1', ['lock_token' => 'tok', 'fencing_token' => 7]);

        self::assertSame(JobEndpointResult::STATUS_SUCCESS, $result->status());
        self::assertSame(7, $result->payload()['fencing_token'] ?? null);
    }

    private function actorContextResolver(string $id): JobEndpointActorContextResolver
    {
        $gateway = $this->createMock(AuthenticatedUserGateway::class);
        $gateway->method('currentUser')->willReturn(['id' => $id, 'email' => 'a@b.c', 'roles' => ['ROLE_AGENT']]);

        return new JobEndpointActorContextResolver(new ResolveAuthenticatedUserHandler($gateway));
    }
}
