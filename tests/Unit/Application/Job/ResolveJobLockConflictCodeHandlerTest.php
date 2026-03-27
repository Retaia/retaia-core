<?php

namespace App\Tests\Unit\Application\Job;

use App\Application\Job\Port\JobGateway;
use App\Application\Job\ResolveJobLockConflictCodeHandler;
use App\Job\Job;
use App\Job\JobStatus;
use PHPUnit\Framework\TestCase;

final class ResolveJobLockConflictCodeHandlerTest extends TestCase
{
    public function testHandleReturnsStaleTokenWhenJobClaimedByAnotherToken(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::once())->method('find')->with('job-1')->willReturn(
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::CLAIMED, 'agent-1', 'right-token', null, [], [], null, 2)
        );

        $handler = new ResolveJobLockConflictCodeHandler($gateway);

        self::assertSame('STALE_LOCK_TOKEN', $handler->handle('job-1', 'agent-1', 'wrong-token', 2));
    }

    public function testHandleReturnsLockInvalidWhenClaimDoesNotMatchStaleCriteria(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::exactly(2))->method('find')->with('job-1')->willReturnOnConsecutiveCalls(
            null,
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::PENDING, null, null, null, [])
        );

        $handler = new ResolveJobLockConflictCodeHandler($gateway);

        self::assertSame('LOCK_INVALID', $handler->handle('job-1', 'agent-1', 'any-token', 1));
        self::assertSame('LOCK_INVALID', $handler->handle('job-1', 'agent-1', 'any-token', 1));
    }
}
