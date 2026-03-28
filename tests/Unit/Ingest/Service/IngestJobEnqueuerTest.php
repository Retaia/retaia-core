<?php

namespace App\Tests\Unit\Ingest\Service;

use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Ingest\Service\IngestJobEnqueuer;
use App\Job\Repository\JobRepository;
use App\Storage\BusinessStorageRegistryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class IngestJobEnqueuerTest extends TestCase
{
    public function testEnqueueRequiredJobsPersistsVersionAndSkipsPreviewWhenProxyExists(): void
    {
        $asset = new Asset(
            uuid: 'asset-1',
            mediaType: 'VIDEO',
            filename: 'clip.mov',
            fields: [],
        );

        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->expects(self::once())->method('save')->with($asset);

        $jobs = new JobRepository($this->connection(), $this->createMock(BusinessStorageRegistryInterface::class));

        $service = new IngestJobEnqueuer($jobs, $assets, new NullLogger());

        self::assertSame(2, $service->enqueueRequiredJobs($asset, true));
        self::assertSame('1', $asset->getFields()['review_processing_version'] ?? null);
        self::assertSame(
            2,
            (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM processing_job WHERE asset_uuid = :assetUuid', [
                'assetUuid' => 'asset-1',
            ])
        );
        self::assertSame(
            0,
            (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM processing_job WHERE asset_uuid = :assetUuid AND job_type = :jobType', [
                'assetUuid' => 'asset-1',
                'jobType' => 'generate_preview',
            ])
        );
    }

    private function connection(): Connection
    {
        static $connection = null;
        if ($connection instanceof Connection) {
            return $connection;
        }

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement('CREATE TABLE processing_job (id VARCHAR(32) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, job_type VARCHAR(64) NOT NULL, state_version VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, correlation_id VARCHAR(64) DEFAULT NULL, claimed_by VARCHAR(64) DEFAULT NULL, claimed_at DATETIME DEFAULT NULL, lock_token VARCHAR(64) DEFAULT NULL, fencing_token INTEGER DEFAULT NULL, locked_until DATETIME DEFAULT NULL, completed_by VARCHAR(64) DEFAULT NULL, completed_at DATETIME DEFAULT NULL, failed_by VARCHAR(64) DEFAULT NULL, failed_at DATETIME DEFAULT NULL, result_payload CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX processing_job_asset_state_unique ON processing_job (asset_uuid, job_type, state_version)');

        return $connection;
    }
}
