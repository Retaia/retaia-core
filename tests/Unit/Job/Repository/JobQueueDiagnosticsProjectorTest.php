<?php

namespace App\Tests\Unit\Job\Repository;

use App\Job\Repository\JobQueueDiagnosticsProjector;
use PHPUnit\Framework\TestCase;

final class JobQueueDiagnosticsProjectorTest extends TestCase
{
    public function testProjectBuildsSummaryAndOldestPendingAge(): void
    {
        $projector = new JobQueueDiagnosticsProjector();
        $result = $projector->project(
            [
                ['status' => 'pending', 'total' => 3],
                ['status' => 'claimed', 'total' => 2],
                ['status' => 'failed', 'total' => 1],
            ],
            [
                ['job_type' => 'generate_preview', 'status' => 'pending', 'total' => 2],
                ['job_type' => 'generate_preview', 'status' => 'failed', 'total' => 1],
                ['job_type' => 'transcribe_audio', 'status' => 'claimed', 'total' => 2],
            ],
            [
                ['job_type' => 'generate_preview', 'oldest_pending_at' => '2026-03-30 11:59:30'],
            ],
            new \DateTimeImmutable('2026-03-30 12:00:00')
        );

        self::assertSame([
            'pending_total' => 3,
            'claimed_total' => 2,
            'failed_total' => 1,
        ], $result['summary']);
        self::assertSame(30, $result['by_type'][0]['oldest_pending_age_seconds']);
    }
}
