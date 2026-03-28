<?php

namespace App\Tests\Unit\Api\Service;

use App\Api\Service\AgentJobProjectionRepository;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AgentJobProjectionRepositoryTest extends TestCase
{
    public function testSnapshotsForAgentsReturnsCurrentSuccessfulAndFailedJobs(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement('CREATE TABLE processing_job (id VARCHAR(36) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, job_type VARCHAR(64) NOT NULL, state_version VARCHAR(64) NOT NULL DEFAULT \'1\', status VARCHAR(16) NOT NULL, correlation_id VARCHAR(64) DEFAULT NULL, claimed_by VARCHAR(32) DEFAULT NULL, claimed_at DATETIME DEFAULT NULL, lock_token VARCHAR(64) DEFAULT NULL, fencing_token INTEGER DEFAULT NULL, locked_until DATETIME DEFAULT NULL, completed_by VARCHAR(32) DEFAULT NULL, completed_at DATETIME DEFAULT NULL, failed_by VARCHAR(32) DEFAULT NULL, failed_at DATETIME DEFAULT NULL, result_payload CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');

        $connection->insert('processing_job', [
            'id' => 'job-current',
            'asset_uuid' => 'asset-current',
            'job_type' => 'extract_facts',
            'status' => 'claimed',
            'claimed_by' => 'agent-1',
            'claimed_at' => '2026-03-28 10:00:00',
            'locked_until' => '2026-03-28 10:05:00',
            'created_at' => '2026-03-28 09:59:00',
            'updated_at' => '2026-03-28 10:00:00',
        ]);
        $connection->insert('processing_job', [
            'id' => 'job-success',
            'asset_uuid' => 'asset-success',
            'job_type' => 'generate_preview',
            'status' => 'completed',
            'completed_by' => 'agent-1',
            'completed_at' => '2026-03-28 09:30:00',
            'result_payload' => json_encode(['ok' => true], JSON_THROW_ON_ERROR),
            'created_at' => '2026-03-28 09:00:00',
            'updated_at' => '2026-03-28 09:30:00',
        ]);
        $connection->insert('processing_job', [
            'id' => 'job-failure',
            'asset_uuid' => 'asset-failure',
            'job_type' => 'transcribe_audio',
            'status' => 'failed',
            'failed_by' => 'agent-1',
            'failed_at' => '2026-03-28 09:45:00',
            'result_payload' => json_encode(['error_code' => 'UPSTREAM_TIMEOUT'], JSON_THROW_ON_ERROR),
            'created_at' => '2026-03-28 09:40:00',
            'updated_at' => '2026-03-28 09:45:00',
        ]);

        $repository = new AgentJobProjectionRepository($connection);
        $snapshots = $repository->snapshotsForAgents(['agent-1']);

        self::assertSame('job-current', $snapshots['agent-1']['current_job']['job_id'] ?? null);
        self::assertSame('extract_facts', $snapshots['agent-1']['current_job']['job_type'] ?? null);
        self::assertSame('job-success', $snapshots['agent-1']['last_successful_job']['job_id'] ?? null);
        self::assertSame('job-failure', $snapshots['agent-1']['last_failed_job']['job_id'] ?? null);
        self::assertSame('UPSTREAM_TIMEOUT', $snapshots['agent-1']['last_failed_job']['error_code'] ?? null);
    }
}
