<?php

namespace App\Tests\Unit\Application\Job;

use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Job\JobEndpointActorContextResolver;
use App\Application\Job\JobEndpointResult;
use App\Application\Job\JobListEndpointHandler;
use App\Application\Job\ListClaimableJobsHandler;
use App\Application\Job\JobContractPolicy;
use App\Application\Job\Port\JobGateway;
use App\Job\Job;
use App\Job\JobStatus;
use PHPUnit\Framework\TestCase;

final class JobListEndpointHandlerTest extends TestCase
{
    public function testReturnsItemsAndActorId(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::once())->method('listClaimable')->with(10)->willReturn([
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::PENDING, null, null, null),
        ]);
        $listHandler = new ListClaimableJobsHandler($gateway, new JobContractPolicy());

        $actorContext = $this->actorContextResolver('agent-1', ['ROLE_AGENT']);

        $result = (new JobListEndpointHandler($listHandler, $actorContext))->handle(10);

        self::assertSame(JobEndpointResult::STATUS_SUCCESS, $result->status());
        self::assertSame('agent-1', $result->actorId());
        self::assertSame('job-1', $result->payload()['items'][0]['job_id'] ?? null);
        self::assertSame('asset-1', $result->payload()['items'][0]['asset_uuid'] ?? null);
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
