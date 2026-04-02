<?php

namespace App\Tests\Unit\Job\Repository;

use App\Job\Repository\JobQueueWriter;
use App\Tests\Support\ProcessingJobSchemaTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class JobQueueWriterTest extends TestCase
{
    use ProcessingJobSchemaTrait;

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
        $this->createProcessingJobTable($this->connection, [
            'unique_index' => 'processing_job_asset_state_unique',
            'unique_columns' => 'asset_uuid, job_type, state_version',
        ]);

        return $this->connection;
    }
}
