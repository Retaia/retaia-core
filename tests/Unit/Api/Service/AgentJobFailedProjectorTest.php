<?php

namespace App\Tests\Unit\Api\Service;

use App\Api\Service\AgentJobFailedProjector;
use App\Api\Service\AgentJobProjectionRowMapper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AgentJobFailedProjectorTest extends TestCase
{
    public function testProjectReturnsMostRecentFailedJobWithErrorCode(): void
    {
        $connection = $this->connection();
        $connection->insert('processing_job', [
            'id' => 'job-no-code',
            'asset_uuid' => 'asset-old',
            'job_type' => 'extract_facts',
            'status' => 'failed',
            'failed_by' => 'agent-1',
            'failed_at' => '2026-03-28 09:00:00',
            'result_payload' => json_encode(['message' => 'missing code'], JSON_THROW_ON_ERROR),
            'created_at' => '2026-03-28 08:00:00',
            'updated_at' => '2026-03-28 09:00:00',
        ]);
        $connection->insert('processing_job', [
            'id' => 'job-failure',
            'asset_uuid' => 'asset-new',
            'job_type' => 'transcribe_audio',
            'status' => 'failed',
            'failed_by' => 'agent-1',
            'failed_at' => '2026-03-28 09:45:00',
            'result_payload' => json_encode(['error_code' => 'UPSTREAM_TIMEOUT'], JSON_THROW_ON_ERROR),
            'created_at' => '2026-03-28 09:40:00',
            'updated_at' => '2026-03-28 09:45:00',
        ]);

        $projector = new AgentJobFailedProjector($connection, new AgentJobProjectionRowMapper());
        $snapshots = $projector->project(['agent-1']);

        self::assertSame('job-failure', $snapshots['agent-1']['job_id']);
        self::assertSame('UPSTREAM_TIMEOUT', $snapshots['agent-1']['error_code']);
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement("CREATE TABLE processing_job (id VARCHAR(36) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, job_type VARCHAR(64) NOT NULL, state_version VARCHAR(64) NOT NULL DEFAULT '1', status VARCHAR(16) NOT NULL, correlation_id VARCHAR(64) DEFAULT NULL, claimed_by VARCHAR(32) DEFAULT NULL, claimed_at DATETIME DEFAULT NULL, lock_token VARCHAR(64) DEFAULT NULL, fencing_token INTEGER DEFAULT NULL, locked_until DATETIME DEFAULT NULL, completed_by VARCHAR(32) DEFAULT NULL, completed_at DATETIME DEFAULT NULL, failed_by VARCHAR(32) DEFAULT NULL, failed_at DATETIME DEFAULT NULL, result_payload CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)");

        return $connection;
    }
}
