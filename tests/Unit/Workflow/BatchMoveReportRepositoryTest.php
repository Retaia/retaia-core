<?php

namespace App\Tests\Unit\Workflow;

use App\Workflow\BatchMoveReportRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class BatchMoveReportRepositoryTest extends TestCase
{
    public function testStoreAndFindBatchReport(): void
    {
        $repository = new BatchMoveReportRepository($this->connection());

        $repository->store('batch-1', [
            'batch_id' => 'batch-1',
            'success_count' => 1,
            'error_count' => 0,
        ]);

        self::assertSame([
            'batch_id' => 'batch-1',
            'success_count' => 1,
            'error_count' => 0,
        ], $repository->find('batch-1'));
        self::assertNull($repository->find('missing'));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement('CREATE TABLE batch_move_report (batch_id VARCHAR(16) PRIMARY KEY NOT NULL, payload CLOB NOT NULL, created_at DATETIME NOT NULL)');

        return $connection;
    }
}
