<?php

namespace App\Tests\Unit\Application\Job;

use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Job\ClaimJobHandler;
use App\Application\Job\JobClaimEndpointHandler;
use App\Application\Job\JobEndpointActorContextResolver;
use App\Application\Job\JobEndpointResult;
use App\Application\Job\JobContractPolicy;
use App\Application\Job\Port\JobGateway;
use App\Job\Job;
use App\Job\JobStatus;
use PHPUnit\Framework\TestCase;

final class JobClaimEndpointHandlerTest extends TestCase
{
    public function testReturnsSuccessWithClaimedJob(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::once())->method('find')->willReturn(
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::PENDING, null, null, null)
        );
        $gateway->expects(self::once())->method('claim')->willReturn(
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::CLAIMED, 'agent-1', 'tok', null)
        );
        $claimHandler = new ClaimJobHandler($gateway, new JobContractPolicy());

        $actorContext = $this->actorContextResolver('agent-1', ['ROLE_AGENT']);

        $result = (new JobClaimEndpointHandler($claimHandler, $actorContext))->handle('job-1');

        self::assertSame(JobEndpointResult::STATUS_SUCCESS, $result->status());
        self::assertSame('agent-1', $result->actorId());
        self::assertInstanceOf(Job::class, $result->job());
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
