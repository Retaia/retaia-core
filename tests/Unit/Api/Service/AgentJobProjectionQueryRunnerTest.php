<?php

namespace App\Tests\Unit\Api\Service;

use App\Api\Service\AgentJobProjectionQueryRunner;
use App\Tests\Support\ProcessingJobSchemaTrait;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AgentJobProjectionQueryRunnerTest extends TestCase
{
    use ProcessingJobSchemaTrait;

    public function testFetchRowsForIdsBuildsStableNamedPlaceholders(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createProcessingJobTable($connection, [
            'id_length' => 36,
            'state_version_length' => 64,
            'state_version_default' => '1',
            'actor_length' => 32,
        ]);
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

        $runner = new AgentJobProjectionQueryRunner($connection);
        $rows = $runner->fetchRowsForIds(
            'SELECT claimed_by AS agent_id, id FROM processing_job WHERE status = :status AND claimed_by IN (%s)',
            'claimed_by',
            ['agent-1'],
            ['status' => 'claimed']
        );

        self::assertCount(1, $rows);
        self::assertSame('job-current', $rows[0]['id'] ?? null);
    }
}
