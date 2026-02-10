<?php

namespace App\Tests\Unit\Derived;

use App\Derived\Service\DerivedUploadService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DerivedUploadServiceTest extends TestCase
{
    public function testInitCreatesOpenUploadSession(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'derived_upload_session',
                self::callback(static fn (array $values): bool => ($values['asset_uuid'] ?? null) === 'asset-1'
                    && ($values['status'] ?? null) === 'open'
                    && is_string($values['upload_id'] ?? null)
                    && strlen((string) $values['upload_id']) === 24)
            );

        $service = new DerivedUploadService($connection);
        $result = $service->init('asset-1', 'proxy', 'video/mp4', 1024, null);

        self::assertSame('open', $result['status']);
        self::assertSame(5 * 1024 * 1024, $result['part_size_bytes']);
        self::assertIsString($result['upload_id']);
    }

    public function testAddPartRejectsInvalidOrClosedSession(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAssociative')->willReturn(['status' => 'completed']);
        $connection->expects(self::never())->method('update');

        $service = new DerivedUploadService($connection);
        self::assertFalse($service->addPart('up-1', 1));
    }

    public function testAddPartUpdatesHighestPartCount(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAssociative')->willReturn([
            'upload_id' => 'up-2',
            'status' => 'open',
            'parts_count' => 2,
        ]);
        $connection
            ->expects(self::once())
            ->method('update')
            ->with(
                'derived_upload_session',
                self::callback(static fn (array $values): bool => ($values['parts_count'] ?? null) === 5),
                ['upload_id' => 'up-2']
            );

        $service = new DerivedUploadService($connection);
        self::assertTrue($service->addPart('up-2', 5));
    }

    public function testCompleteReturnsNullWhenSessionCannotBeCompleted(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(3))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                false,
                ['status' => 'completed'],
                ['status' => 'open', 'asset_uuid' => 'other', 'parts_count' => 5],
            );
        $connection->expects(self::never())->method('insert');

        $service = new DerivedUploadService($connection);

        self::assertNull($service->complete('asset-1', 'up-1', 1));
        self::assertNull($service->complete('asset-1', 'up-2', 1));
        self::assertNull($service->complete('asset-1', 'up-3', 1));
    }

    public function testCompleteCreatesDerivedFileAndMarksSessionCompleted(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAssociative')->willReturn([
            'upload_id' => 'up-4',
            'asset_uuid' => 'asset-4',
            'kind' => 'proxy',
            'content_type' => 'video/mp4',
            'size_bytes' => 2000,
            'sha256' => 'hash',
            'status' => 'open',
            'parts_count' => 2,
        ]);
        $connection->expects(self::once())->method('insert');
        $connection->expects(self::once())->method('update')->with(
            'derived_upload_session',
            self::callback(static fn (array $values): bool => ($values['status'] ?? null) === 'completed'),
            ['upload_id' => 'up-4']
        );

        $service = new DerivedUploadService($connection);
        $result = $service->complete('asset-4', 'up-4', 2);

        self::assertIsArray($result);
        self::assertSame('asset-4', $result['asset_uuid']);
        self::assertSame('proxy', $result['kind']);
        self::assertSame('/api/v1/assets/asset-4/derived/proxy', $result['url']);
    }

    public function testListAndFindNormalizeRows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturn([
            [
                'id' => 'd-1',
                'asset_uuid' => 'asset-7',
                'kind' => 'proxy',
                'content_type' => 'video/mp4',
                'size_bytes' => 77,
                'sha256' => 'hash',
                'storage_path' => '/tmp/x',
                'created_at' => '2026-01-01 10:00:00',
            ],
        ]);
        $connection->expects(self::exactly(2))->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    'id' => 'd-2',
                    'asset_uuid' => 'asset-7',
                    'kind' => 'thumbnail',
                    'content_type' => 'image/png',
                    'size_bytes' => 10,
                    'sha256' => null,
                    'storage_path' => '/tmp/y',
                    'created_at' => '2026-01-01 10:05:00',
                ],
                false
            );

        $service = new DerivedUploadService($connection);
        $list = $service->listForAsset('asset-7');
        $found = $service->findByAssetAndKind('asset-7', 'thumbnail');
        $notFound = $service->findByAssetAndKind('asset-7', 'missing');

        self::assertCount(1, $list);
        self::assertSame('/api/v1/assets/asset-7/derived/proxy', $list[0]['url']);
        self::assertSame('thumbnail', $found['kind']);
        self::assertNull($notFound);
    }
}
