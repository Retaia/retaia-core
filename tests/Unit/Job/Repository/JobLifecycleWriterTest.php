<?php

namespace App\Tests\Unit\Job\Repository;

use App\Job\Repository\JobLifecycleWriter;
use App\Tests\Support\ProcessingJobSchemaTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class JobLifecycleWriterTest extends TestCase
{
    use ProcessingJobSchemaTrait;

    private ?Connection $connection = null;

    public function testClaimHeartbeatSubmitAndFailLifecycle(): void
    {
        $writer = new JobLifecycleWriter($this->connection());

        self::assertTrue($writer->claim('job-1', 'agent-1', 300));
        $claimed = $this->connection()->fetchAssociative('SELECT claimed_by, lock_token, fencing_token FROM processing_job WHERE id = :id', ['id' => 'job-1']);
        self::assertSame('agent-1', (string) $claimed['claimed_by']);
        $lockToken = (string) $claimed['lock_token'];
        $fencingToken = (int) $claimed['fencing_token'];

        self::assertTrue($writer->heartbeat('job-1', 'agent-1', $lockToken, $fencingToken, 300));
        $afterHeartbeat = $this->connection()->fetchAssociative('SELECT fencing_token FROM processing_job WHERE id = :id', ['id' => 'job-1']);
        $nextFencing = (int) $afterHeartbeat['fencing_token'];

        self::assertTrue($writer->submit('job-1', 'agent-1', $lockToken, $nextFencing, ['ok' => true]));
        self::assertSame('completed', (string) $this->connection()->fetchOne('SELECT status FROM processing_job WHERE id = :id', ['id' => 'job-1']));

        $this->seedPendingJob('job-2');
        self::assertTrue($writer->claim('job-2', 'agent-2', 300));
        $claimedTwo = $this->connection()->fetchAssociative('SELECT lock_token, fencing_token FROM processing_job WHERE id = :id', ['id' => 'job-2']);
        self::assertTrue($writer->fail('job-2', 'agent-2', (string) $claimedTwo['lock_token'], (int) $claimedTwo['fencing_token'], false, 'E_TEST', 'Broken'));
        self::assertSame('failed', (string) $this->connection()->fetchOne('SELECT status FROM processing_job WHERE id = :id', ['id' => 'job-2']));
    }

    public function testHasActiveJobForAgentReflectsClaimedRows(): void
    {
        $writer = new JobLifecycleWriter($this->connection());

        self::assertFalse($writer->hasActiveJobForAgent('agent-z'));
        self::assertTrue($writer->claim('job-1', 'agent-z', 300));
        self::assertTrue($writer->hasActiveJobForAgent('agent-z'));
    }

    private function connection(): Connection
    {
        if ($this->connection instanceof Connection) {
            return $this->connection;
        }

        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->connection->executeStatement('CREATE TABLE asset (uuid VARCHAR(36) PRIMARY KEY NOT NULL, state VARCHAR(32) NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE asset_operation_lock (id VARCHAR(32) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, lock_type VARCHAR(32) NOT NULL, actor_id VARCHAR(64) NOT NULL, acquired_at DATETIME NOT NULL, released_at DATETIME DEFAULT NULL)');
        $this->createProcessingJobTable($this->connection);
        $this->connection->executeStatement("INSERT INTO asset (uuid, state) VALUES ('asset-1', 'READY')");
        $this->seedPendingJobOn($this->connection, 'job-1');

        return $this->connection;
    }

    private function seedPendingJob(string $id): void
    {
        $this->seedPendingJobOn($this->connection(), $id);
    }

    private function seedPendingJobOn(Connection $connection, string $id): void
    {
        $connection->insert('processing_job', [
            'id' => $id,
            'asset_uuid' => 'asset-1',
            'job_type' => 'generate_preview',
            'state_version' => '1',
            'status' => 'pending',
            'correlation_id' => null,
            'claimed_by' => null,
            'claimed_at' => null,
            'lock_token' => null,
            'fencing_token' => null,
            'locked_until' => null,
            'completed_by' => null,
            'completed_at' => null,
            'failed_by' => null,
            'failed_at' => null,
            'result_payload' => null,
            'created_at' => '2026-03-30 12:00:00',
            'updated_at' => '2026-03-30 12:00:00',
        ]);
    }
}
