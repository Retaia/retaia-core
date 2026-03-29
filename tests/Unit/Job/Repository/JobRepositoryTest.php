<?php

namespace App\Tests\Unit\Job\Repository;

use App\Job\JobStatus;
use App\Job\Repository\JobQueueDiagnosticsProjector;
use App\Job\Repository\JobRepository;
use App\Job\Repository\JobSourceProjector;
use App\Storage\BusinessStorageRegistryInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class JobRepositoryTest extends TestCase
{
    public function testQueueDiagnosticsSnapshotReturnsProjectedData(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [['status' => 'pending', 'total' => 2]],
            [['job_type' => 'generate_preview', 'status' => 'pending', 'total' => 2]],
            [['job_type' => 'generate_preview', 'oldest_pending_at' => '2026-03-30 12:00:00']]
        );

        $registry = $this->createMock(BusinessStorageRegistryInterface::class);
        $repository = new JobRepository(
            $connection,
            $registry,
            new JobQueueDiagnosticsProjector(),
            new JobSourceProjector($registry),
        );

        $result = $repository->queueDiagnosticsSnapshot();

        self::assertSame(2, $result['summary']['pending_total']);
        self::assertSame('generate_preview', $result['by_type'][0]['job_type']);
    }

    public function testFindHydratesSourceFromCanonicalAssetPaths(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'id' => 'job-1',
            'asset_uuid' => 'asset-1',
            'job_type' => 'generate_preview',
            'status' => JobStatus::PENDING->value,
            'claimed_by' => null,
            'lock_token' => null,
            'fencing_token' => null,
            'locked_until' => null,
            'result_payload' => null,
            'correlation_id' => null,
            'asset_fields' => json_encode([
                'paths' => [
                    'storage_id' => 'nas-main',
                    'original_relative' => 'INBOX/clip.mp4',
                    'sidecars_relative' => ['INBOX/clip.srt'],
                ],
            ], JSON_THROW_ON_ERROR),
            'asset_filename' => 'clip.mp4',
        ]);

        $registry = $this->createMock(BusinessStorageRegistryInterface::class);
        $registry->method('has')->with('nas-main')->willReturn(true);

        $repository = new JobRepository(
            $connection,
            $registry,
            new JobQueueDiagnosticsProjector(),
            new JobSourceProjector($registry),
        );

        $job = $repository->find('job-1');

        self::assertNotNull($job);
        self::assertSame('nas-main', $job->source['storage_id']);
        self::assertSame('INBOX/clip.mp4', $job->source['original_relative']);
    }
}
