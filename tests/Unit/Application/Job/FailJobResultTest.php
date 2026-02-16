<?php

namespace App\Tests\Unit\Application\Job;

use App\Application\Job\FailJobResult;
use App\Job\Job;
use App\Job\JobStatus;
use PHPUnit\Framework\TestCase;

final class FailJobResultTest extends TestCase
{
    public function testExposesFailedStatusAndJob(): void
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

        $result = new FailJobResult(FailJobResult::STATUS_FAILED, $job);

        self::assertSame(FailJobResult::STATUS_FAILED, $result->status());
        self::assertSame($job, $result->job());
    }

    public function testExposesConflictStatusWithoutJob(): void
    {
        $result = new FailJobResult(FailJobResult::STATUS_STALE_LOCK_TOKEN);

        self::assertSame(FailJobResult::STATUS_STALE_LOCK_TOKEN, $result->status());
        self::assertNull($result->job());
    }
}
