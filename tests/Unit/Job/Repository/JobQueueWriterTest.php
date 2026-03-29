<?php

namespace App\Tests\Unit\Job\Repository;

use App\Job\Repository\JobQueueWriter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class JobQueueWriterTest extends TestCase
{
    private ?Connection $connection = null;

    public function testEnqueuePendingPersistsJobAndReturnsId(): void
    {
        $writer = new JobQueueWriter($this->connection());

        $id = $writer->enqueuePending('asset-1', 'generate_preview', '3');

        self::assertNotSame('', $id);
        self::assertSame(
            '3',
            (string) $this->connection()->fetchOne('SELECT state_version FROM processing_job WHERE id = :id', ['id' => $id])
        );
    }

    public function testEnqueuePendingIfMissingHonorsUniqueConstraint(): void
    {
        $writer = new JobQueueWriter($this->connection());

        self::assertTrue($writer->enqueuePendingIfMissing('asset-1', 'generate_preview', '1', 'corr-1'));
        self::assertFalse($writer->enqueuePendingIfMissing('asset-1', 'generate_preview', '1', 'corr-2'));
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
        $this->connection->executeStatement('CREATE TABLE processing_job (id VARCHAR(32) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, job_type VARCHAR(64) NOT NULL, state_version VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, correlation_id VARCHAR(64) DEFAULT NULL, claimed_by VARCHAR(64) DEFAULT NULL, claimed_at DATETIME DEFAULT NULL, lock_token VARCHAR(64) DEFAULT NULL, fencing_token INTEGER DEFAULT NULL, locked_until DATETIME DEFAULT NULL, completed_by VARCHAR(64) DEFAULT NULL, completed_at DATETIME DEFAULT NULL, failed_by VARCHAR(64) DEFAULT NULL, failed_at DATETIME DEFAULT NULL, result_payload CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->connection->executeStatement('CREATE UNIQUE INDEX processing_job_asset_state_unique ON processing_job (asset_uuid, job_type, state_version)');

        return $this->connection;
    }
}
