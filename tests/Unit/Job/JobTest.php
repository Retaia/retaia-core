<?php

namespace App\Tests\Unit\Job;

use App\Job\Job;
use App\Job\JobStatus;
use PHPUnit\Framework\TestCase;

final class JobTest extends TestCase
{
    public function testToArrayNormalizesJobPayload(): void
    {
        $lockedUntil = new \DateTimeImmutable('2026-01-01T10:00:00+00:00');
        $job = new Job(
            id: 'job-1',
            assetUuid: 'asset-1',
            jobType: 'proxy',
            status: JobStatus::CLAIMED,
            claimedBy: 'agent-1',
            lockToken: 'lock-123',
            lockedUntil: $lockedUntil,
            result: ['ok' => true],
            source: [
                'storage_id' => 'nas-main',
                'original_relative' => 'INBOX/file.mov',
                'sidecars_relative' => ['INBOX/file.srt'],
            ],
        );

        self::assertSame([
            'job_id' => 'job-1',
            'asset_uuid' => 'asset-1',
            'job_type' => 'proxy',
            'status' => 'claimed',
            'source' => [
                'storage_id' => 'nas-main',
                'original_relative' => 'INBOX/file.mov',
                'sidecars_relative' => ['INBOX/file.srt'],
            ],
            'required_capabilities' => [],
            'claimed_by' => 'agent-1',
            'lock_token' => 'lock-123',
            'locked_until' => $lockedUntil->format(DATE_ATOM),
        ], $job->toArray());
    }
}
