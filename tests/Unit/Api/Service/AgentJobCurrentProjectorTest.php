<?php

namespace App\Tests\Unit\Api\Service;

use App\Api\Service\AgentJobCurrentProjector;
use App\Api\Service\AgentJobProjectionQueryRunner;
use App\Api\Service\AgentJobProjectionRowMapper;
use App\Tests\Support\ProcessingJobSchemaTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AgentJobCurrentProjectorTest extends TestCase
{
    use ProcessingJobSchemaTrait;

    public function testProjectReturnsMostRecentCurrentJobPerAgent(): void
    {
        $connection = $this->connection();
        $connection->insert('processing_job', [
            'id' => 'job-old',
            'asset_uuid' => 'asset-old',
            'job_type' => 'extract_facts',
            'status' => 'claimed',
            'claimed_by' => 'agent-1',
            'claimed_at' => '2026-03-28 10:00:00',
            'locked_until' => '2026-03-28 10:05:00',
            'created_at' => '2026-03-28 09:59:00',
            'updated_at' => '2026-03-28 10:00:00',
        ]);
        $connection->insert('processing_job', [
            'id' => 'job-new',
            'asset_uuid' => 'asset-new',
            'job_type' => 'generate_preview',
            'status' => 'claimed',
            'claimed_by' => 'agent-1',
            'claimed_at' => '2026-03-28 10:01:00',
            'locked_until' => '2026-03-28 10:06:00',
            'created_at' => '2026-03-28 10:00:30',
            'updated_at' => '2026-03-28 10:01:00',
        ]);

        $projector = new AgentJobCurrentProjector(new AgentJobProjectionQueryRunner($connection), new AgentJobProjectionRowMapper());
        $snapshots = $projector->project(['agent-1']);

        self::assertSame('job-new', $snapshots['agent-1']['job_id']);
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
