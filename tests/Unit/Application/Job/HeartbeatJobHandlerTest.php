<?php

namespace App\Tests\Unit\Application\Job;

use App\Application\Job\HeartbeatJobHandler;
use App\Application\Job\HeartbeatJobResult;
use App\Application\Job\Port\JobGateway;
use App\Application\Job\ResolveJobLockConflictCodeHandler;
use App\Job\Job;
use App\Job\JobStatus;
use PHPUnit\Framework\TestCase;

final class HeartbeatJobHandlerTest extends TestCase
{
    public function testHandleReturnsHeartbeatWhenGatewaySucceeds(): void
    {
        $job = new Job('job-1', 'asset-1', 'extract_facts', JobStatus::CLAIMED, 'agent-1', 'token', new \DateTimeImmutable('+5 minutes'), []);

        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::once())->method('heartbeat')->with('job-1', 'token', 300)->willReturn($job);
        $gateway->expects(self::never())->method('find');

        $result = (new HeartbeatJobHandler($gateway, new ResolveJobLockConflictCodeHandler($gateway)))->handle('job-1', 'token');

        self::assertSame(HeartbeatJobResult::STATUS_HEARTBEATED, $result->status());
        self::assertSame($job, $result->job());
    }

    public function testHandleMapsLockConflictStatusesWhenGatewayFails(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::exactly(2))->method('heartbeat')->with('job-1', 'wrong-token', 300)->willReturn(null);
        $gateway->expects(self::exactly(2))->method('find')->with('job-1')->willReturnOnConsecutiveCalls(
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::CLAIMED, 'agent-1', 'right-token', null, []),
            null
        );

        $handler = new HeartbeatJobHandler($gateway, new ResolveJobLockConflictCodeHandler($gateway));

        self::assertSame(HeartbeatJobResult::STATUS_STALE_LOCK_TOKEN, $handler->handle('job-1', 'wrong-token')->status());
        self::assertSame(HeartbeatJobResult::STATUS_LOCK_INVALID, $handler->handle('job-1', 'wrong-token')->status());
    }
}
