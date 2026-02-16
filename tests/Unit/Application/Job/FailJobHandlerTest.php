<?php

namespace App\Tests\Unit\Application\Job;

use App\Application\Job\FailJobHandler;
use App\Application\Job\FailJobResult;
use App\Application\Job\Port\JobGateway;
use App\Application\Job\ResolveJobLockConflictCodeHandler;
use App\Job\Job;
use App\Job\JobStatus;
use PHPUnit\Framework\TestCase;

final class FailJobHandlerTest extends TestCase
{
    public function testReturnsFailedWhenGatewayFailsJob(): void
    {
        $job = new Job(
            'job-1',
            'asset-1',
            'suggest_tags',
            JobStatus::FAILED,
            'agent-1',
            'lock-1',
            new \DateTimeImmutable('+5 minutes'),
            []
        );
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::once())
            ->method('fail')
            ->with('job-1', 'lock-1', true, 'E_RUNTIME', 'failed')
            ->willReturn($job);
        $gateway->expects(self::never())->method('find');

        $handler = new FailJobHandler($gateway, new ResolveJobLockConflictCodeHandler($gateway));
        $result = $handler->handle('job-1', 'lock-1', true, 'E_RUNTIME', 'failed');

        self::assertSame(FailJobResult::STATUS_FAILED, $result->status());
        self::assertSame($job, $result->job());
    }

    public function testReturnsStaleLockTokenWhenResolvedAsStale(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::once())
            ->method('fail')
            ->with('job-1', 'lock-from-client', false, 'E_TIMEOUT', 'timeout')
            ->willReturn(null);
        $gateway->expects(self::once())
            ->method('find')
            ->with('job-1')
            ->willReturn(new Job(
                'job-1',
                'asset-1',
                'suggest_tags',
                JobStatus::CLAIMED,
                'agent-1',
                'lock-on-server',
                new \DateTimeImmutable('+5 minutes'),
                []
            ));

        $handler = new FailJobHandler($gateway, new ResolveJobLockConflictCodeHandler($gateway));
        $result = $handler->handle('job-1', 'lock-from-client', false, 'E_TIMEOUT', 'timeout');

        self::assertSame(FailJobResult::STATUS_STALE_LOCK_TOKEN, $result->status());
        self::assertNull($result->job());
    }

    public function testReturnsLockInvalidWhenNoConflictingLockFound(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::once())
            ->method('fail')
            ->with('job-1', 'lock-1', false, 'E_TIMEOUT', 'timeout')
            ->willReturn(null);
        $gateway->expects(self::once())
            ->method('find')
            ->with('job-1')
            ->willReturn(null);

        $handler = new FailJobHandler($gateway, new ResolveJobLockConflictCodeHandler($gateway));
        $result = $handler->handle('job-1', 'lock-1', false, 'E_TIMEOUT', 'timeout');

        self::assertSame(FailJobResult::STATUS_LOCK_INVALID, $result->status());
        self::assertNull($result->job());
    }
}
