<?php

namespace App\Tests\Unit\Application\Job;

use App\Application\Job\CheckSuggestTagsSubmitScopeHandler;
use App\Application\Job\Port\JobGateway;
use App\Application\Job\ResolveJobLockConflictCodeHandler;
use App\Application\Job\SubmitJobHandler;
use App\Application\Job\SubmitJobResult;
use App\Job\Job;
use App\Job\JobStatus;
use PHPUnit\Framework\TestCase;

final class SubmitJobHandlerTest extends TestCase
{
    public function testHandleReturnsForbiddenScopeForSuggestTagsWithoutScope(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::once())->method('find')->with('job-1')->willReturn(
            new Job('job-1', 'asset-1', 'suggest_tags', JobStatus::CLAIMED, 'agent-1', 't', null, [])
        );
        $gateway->expects(self::never())->method('submit');

        $handler = new SubmitJobHandler(
            $gateway,
            new CheckSuggestTagsSubmitScopeHandler(true),
            new ResolveJobLockConflictCodeHandler($gateway)
        );

        $result = $handler->handle('job-1', 't', 'suggest_tags', ['ok' => true], ['ROLE_AGENT']);

        self::assertSame(SubmitJobResult::STATUS_FORBIDDEN_SCOPE, $result->status());
    }

    public function testHandleReturnsLockConflictStatusesWhenSubmitFails(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::exactly(4))->method('find')->with('job-1')->willReturnOnConsecutiveCalls(
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::CLAIMED, 'agent-1', 'right-token', null, []),
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::CLAIMED, 'agent-1', 'right-token', null, []),
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::PENDING, null, null, null, []),
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::PENDING, null, null, null, [])
        );
        $gateway->expects(self::exactly(2))->method('submit')->with('job-1', 'wrong-token', ['ok' => true])->willReturn(null);

        $handler = new SubmitJobHandler(
            $gateway,
            new CheckSuggestTagsSubmitScopeHandler(true),
            new ResolveJobLockConflictCodeHandler($gateway)
        );

        self::assertSame(SubmitJobResult::STATUS_STALE_LOCK_TOKEN, $handler->handle('job-1', 'wrong-token', 'extract_facts', ['ok' => true], ['ROLE_AGENT'])->status());
        self::assertSame(SubmitJobResult::STATUS_LOCK_INVALID, $handler->handle('job-1', 'wrong-token', 'extract_facts', ['ok' => true], ['ROLE_AGENT'])->status());
    }

    public function testHandleReturnsSubmittedWhenGatewaySucceeds(): void
    {
        $job = new Job('job-1', 'asset-1', 'extract_facts', JobStatus::COMPLETED, 'agent-1', null, null, ['ok' => true]);

        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::once())->method('find')->with('job-1')->willReturn(
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::CLAIMED, 'agent-1', 'token', null, [])
        );
        $gateway->expects(self::once())->method('submit')->with('job-1', 'token', ['ok' => true])->willReturn($job);

        $handler = new SubmitJobHandler(
            $gateway,
            new CheckSuggestTagsSubmitScopeHandler(true),
            new ResolveJobLockConflictCodeHandler($gateway)
        );

        $result = $handler->handle('job-1', 'token', 'extract_facts', ['ok' => true], ['ROLE_SUGGESTIONS_WRITE']);

        self::assertSame(SubmitJobResult::STATUS_SUBMITTED, $result->status());
        self::assertSame($job, $result->job());
    }

    public function testHandleReturnsValidationFailedWhenJobTypeDoesNotMatchJob(): void
    {
        $gateway = $this->createMock(JobGateway::class);
        $gateway->expects(self::once())->method('find')->with('job-1')->willReturn(
            new Job('job-1', 'asset-1', 'extract_facts', JobStatus::CLAIMED, 'agent-1', 'token', null, [])
        );
        $gateway->expects(self::never())->method('submit');

        $handler = new SubmitJobHandler(
            $gateway,
            new CheckSuggestTagsSubmitScopeHandler(true),
            new ResolveJobLockConflictCodeHandler($gateway)
        );

        $result = $handler->handle('job-1', 'token', 'generate_proxy', ['ok' => true], ['ROLE_AGENT']);

        self::assertSame(SubmitJobResult::STATUS_VALIDATION_FAILED, $result->status());
    }
}
