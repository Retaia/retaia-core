<?php

namespace App\Tests\Unit\Application\Job;

use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Job\ClaimJobHandler;
use App\Application\Job\FailJobHandler;
use App\Application\Job\HeartbeatJobHandler;
use App\Application\Job\JobEndpointResult;
use App\Application\Job\JobEndpointsHandler;
use App\Application\Job\ListClaimableJobsHandler;
use App\Application\Job\Port\JobGateway;
use App\Application\Job\ResolveJobLockConflictCodeHandler;
use App\Application\Job\SubmitJobHandler;
use App\Application\Job\CheckSuggestTagsSubmitScopeHandler;
use App\Job\Job;
use App\Job\JobStatus;
use PHPUnit\Framework\TestCase;

final class JobEndpointsHandlerTest extends TestCase
{
    public function testClaimReturnsStateConflictWhenGatewayCannotClaim(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::once())->method('claim')->with('job-1', 'agent-1', 300)->willReturn(null);

        $handler = $this->buildHandler($gateway, ['id' => 'agent-1', 'email' => 'a@b.c', 'roles' => ['ROLE_AGENT']]);
        $result = $handler->claim('job-1');

        self::assertSame(JobEndpointResult::STATUS_STATE_CONFLICT, $result->status());
        self::assertSame('agent-1', $result->actorId());
    }

    public function testHeartbeatReturnsLockRequiredWhenTokenMissing(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::never())->method('heartbeat');

        $handler = $this->buildHandler($gateway, ['id' => 'agent-1', 'email' => 'a@b.c', 'roles' => ['ROLE_AGENT']]);
        $result = $handler->heartbeat('job-1', []);

        self::assertSame(JobEndpointResult::STATUS_LOCK_REQUIRED, $result->status());
    }

    public function testSubmitReturnsForbiddenScope(): void
    {
        $job = new Job('job-1', 'asset-1', 'suggest_tags', JobStatus::CLAIMED, 'agent-1', 'token', new \DateTimeImmutable('+5 minutes'), []);
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::once())->method('find')->with('job-1')->willReturn($job);
        $gateway->expects(self::never())->method('submit');

        $handler = $this->buildHandler($gateway, ['id' => 'agent-1', 'email' => 'a@b.c', 'roles' => ['ROLE_AGENT']]);
        $result = $handler->submit('job-1', [
            'lock_token' => 'token',
            'job_type' => 'suggest_tags',
            'result' => ['ok' => true],
        ]);

        self::assertSame(JobEndpointResult::STATUS_FORBIDDEN_SCOPE, $result->status());
    }

    public function testSubmitReturnsValidationFailedWhenJobTypeIsMissing(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::never())->method('submit');

        $handler = $this->buildHandler($gateway, ['id' => 'agent-1', 'email' => 'a@b.c', 'roles' => ['ROLE_AGENT']]);
        $result = $handler->submit('job-1', [
            'lock_token' => 'token',
            'result' => ['ok' => true],
        ]);

        self::assertSame(JobEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testFailReturnsValidationFailedWhenErrorCodeMissing(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::never())->method('fail');

        $handler = $this->buildHandler($gateway, ['id' => 'agent-1', 'email' => 'a@b.c', 'roles' => ['ROLE_AGENT']]);
        $result = $handler->fail('job-1', [
            'lock_token' => 'token',
            'message' => 'failed',
            'retryable' => true,
        ]);

        self::assertSame(JobEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
        self::assertTrue((bool) $result->retryable());
    }

    public function testListUsesAnonymousActorWhenUnauthenticated(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::once())->method('listClaimable')->with(10)->willReturn([]);

        $handler = $this->buildHandler($gateway, null);
        $result = $handler->list(10);

        self::assertSame(JobEndpointResult::STATUS_SUCCESS, $result->status());
        self::assertSame('anonymous', $result->actorId());
        self::assertSame(['items' => []], $result->payload());
    }

    /**
     * @param array{id: string, email: string, roles: array<int, string>}|null $currentUser
     */
    private function buildHandler(JobGateway $gateway, ?array $currentUser): JobEndpointsHandler
    {
        $authenticatedUserGateway = $this->createMock(AuthenticatedUserGateway::class);
        $authenticatedUserGateway->method('currentUser')->willReturn($currentUser);

        return new JobEndpointsHandler(
            new ListClaimableJobsHandler($gateway),
            new ClaimJobHandler($gateway),
            new HeartbeatJobHandler($gateway, new ResolveJobLockConflictCodeHandler($gateway)),
            new SubmitJobHandler(
                $gateway,
                new CheckSuggestTagsSubmitScopeHandler(true),
                new ResolveJobLockConflictCodeHandler($gateway)
            ),
            new FailJobHandler($gateway, new ResolveJobLockConflictCodeHandler($gateway)),
            new ResolveAuthenticatedUserHandler($authenticatedUserGateway),
        );
    }
}
