<?php

namespace App\Tests\Unit\Ingest\Service;

use App\Ingest\Port\ScanStateStoreInterface;
use App\Ingest\Repository\IngestDiagnosticsRepository;
use App\Ingest\Service\BusinessStorageAwareSidecarLocator;
use App\Ingest\Service\ExistingProxyAttachmentService;
use App\Ingest\Service\IngestAssetService;
use App\Ingest\Service\IngestJobEnqueuer;
use App\Ingest\Service\IngestStableFileEnqueueService;
use App\Ingest\Service\SidecarFileDetector;
use App\Storage\BusinessStorageDefinition;
use App\Storage\BusinessStorageInterface;
use App\Storage\BusinessStorageRegistry;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class IngestStableFileEnqueueServiceTest extends TestCase
{
    public function testInvalidRelativePathIsMarkedMissing(): void
    {
        $scanState = $this->createMock(ScanStateStoreInterface::class);
        $scanState->method('listStableFiles')->willReturn([[
            'storage_id' => 'nas-main',
            'path' => '../bad.mov',
            'size' => 1,
            'mtime' => new \DateTimeImmutable(),
            'stable_count' => 2,
            'status' => 'stable',
        ]]);
        $scanState->expects(self::once())->method('markMissing')->with('nas-main', '../bad.mov', self::isInstanceOf(\DateTimeImmutable::class));

        $storage = $this->createMock(BusinessStorageInterface::class);
        $registry = new BusinessStorageRegistry('nas-main', [new BusinessStorageDefinition('nas-main', $storage)]);

        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $callback): array => $callback());
        $diagnosticsConnection = $this->createMock(Connection::class);

        $service = new IngestStableFileEnqueueService(
            $scanState,
            $connection,
            $registry,
            new BusinessStorageAwareSidecarLocator($registry, new SidecarFileDetector()),
            new IngestDiagnosticsRepository($diagnosticsConnection),
            (new \ReflectionClass(ExistingProxyAttachmentService::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(IngestAssetService::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(IngestJobEnqueuer::class))->newInstanceWithoutConstructor(),
            $this->createMock(LoggerInterface::class),
        );

        self::assertSame(['queued' => 0, 'missing' => 1, 'unmatched_sidecars' => 0], $service->enqueueStableFiles(10));
    }
}
