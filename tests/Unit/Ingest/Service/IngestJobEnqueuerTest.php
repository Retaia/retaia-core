<?php

namespace App\Tests\Unit\Ingest\Service;

use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Ingest\Service\IngestJobEnqueuer;
use App\Job\Repository\JobRepository;
use App\Storage\BusinessStorageRegistryInterface;
use App\Tests\Support\ProcessingJobSchemaTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class IngestJobEnqueuerTest extends TestCase
{
    use ProcessingJobSchemaTrait;

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
        $this->createProcessingJobTable($connection, [
            'unique_index' => 'processing_job_asset_state_unique',
            'unique_columns' => 'asset_uuid, job_type, state_version',
        ]);

        return $connection;
    }
}
