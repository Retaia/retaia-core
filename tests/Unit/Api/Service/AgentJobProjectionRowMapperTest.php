<?php

namespace App\Tests\Unit\Api\Service;

use App\Api\Service\AgentJobProjectionRowMapper;
use PHPUnit\Framework\TestCase;

final class AgentJobProjectionRowMapperTest extends TestCase
{
    public function testCurrentAndSuccessfulCandidatesNormalizeDates(): void
    {
        $mapper = new AgentJobProjectionRowMapper();

        $current = $mapper->currentJobCandidate([
            'id' => 'job-1',
            'job_type' => 'extract_facts',
            'asset_uuid' => 'asset-1',
            'claimed_at' => '2026-03-28 10:00:00',
            'locked_until' => '2026-03-28 10:05:00',
        ]);
        $successful = $mapper->successfulJobCandidate([
            'id' => 'job-2',
            'job_type' => 'generate_preview',
            'asset_uuid' => 'asset-2',
            'completed_at' => '2026-03-28 09:30:00',
        ]);

        self::assertSame('2026-03-28T10:00:00+00:00', $current['claimed_at']);
        self::assertSame('2026-03-28T10:05:00+00:00', $current['locked_until']);
        self::assertSame('2026-03-28T09:30:00+00:00', $successful['completed_at']);
    }

    public function testFailedCandidateRequiresDecodedErrorCode(): void
    {
        $mapper = new AgentJobProjectionRowMapper();

        $candidate = $mapper->failedJobCandidate([
            'id' => 'job-3',
            'job_type' => 'transcribe_audio',
            'asset_uuid' => 'asset-3',
            'failed_at' => '2026-03-28 09:45:00',
            'result_payload' => json_encode(['error_code' => 'UPSTREAM_TIMEOUT'], JSON_THROW_ON_ERROR),
        ]);

        self::assertSame('UPSTREAM_TIMEOUT', $candidate['error_code']);
        self::assertSame('2026-03-28T09:45:00+00:00', $candidate['failed_at']);
        self::assertSame([], $mapper->decodeArray('{invalid'));
        self::assertSame('', $mapper->atom('not-a-date'));
    }
}
