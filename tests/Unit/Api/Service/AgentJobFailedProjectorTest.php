<?php

namespace App\Tests\Unit\Api\Service;

use App\Api\Service\AgentJobFailedProjector;
use App\Api\Service\AgentJobProjectionQueryRunner;
use App\Api\Service\AgentJobProjectionRowMapper;
use App\Tests\Support\ProcessingJobSchemaTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AgentJobFailedProjectorTest extends TestCase
{
    use ProcessingJobSchemaTrait;

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

        $projector = new AgentJobFailedProjector(new AgentJobProjectionQueryRunner($connection), new AgentJobProjectionRowMapper());
        $snapshots = $projector->project(['agent-1']);

        self::assertSame('job-failure', $snapshots['agent-1']['job_id']);
        self::assertSame('UPSTREAM_TIMEOUT', $snapshots['agent-1']['error_code']);
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createProcessingJobTable($connection, [
            'id_length' => 36,
            'state_version_length' => 64,
            'state_version_default' => '1',
            'actor_length' => 32,
        ]);

        return $connection;
    }
}
