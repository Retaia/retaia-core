<?php

namespace App\Tests\Unit\Workflow;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Entity\Asset;
use App\Lock\Repository\OperationLockRepository;
use App\Workflow\Service\BatchWorkflowService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class BatchWorkflowServiceOptionalTableTest extends TestCase
{
    public function testPurgeSucceedsWithoutOptionalDerivedTable(): void
    {
        $asset = new Asset('a-r', 'video', 'r.mp4', AssetState::REJECTED);

        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->expects(self::once())->method('save')->with($asset);

        $service = new BatchWorkflowService($assets, new AssetStateMachine(), $this->connectionWithoutDerivedTable(), $this->locks());

        self::assertTrue($service->purge($asset));
        self::assertSame(AssetState::PURGED, $asset->getState());
    }

    private function connectionWithoutDerivedTable(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement('CREATE TABLE processing_job (id VARCHAR(36) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, locked_until DATETIME DEFAULT NULL)');

        return $connection;
    }

    private function locks(): OperationLockRepository
    {
        $locks = $this->createMock(OperationLockRepository::class);
        $locks->method('hasActiveLock')->willReturn(false);
        $locks->method('acquire')->willReturn(true);
        $locks->method('release');

        return $locks;
    }
}
