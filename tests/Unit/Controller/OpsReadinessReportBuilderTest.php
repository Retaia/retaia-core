<?php

namespace App\Tests\Unit\Controller;

use App\Controller\Api\OpsReadinessReportBuilder;
use App\Storage\BusinessStorageDefinition;
use App\Storage\BusinessStorageInterface;
use App\Storage\BusinessStorageRegistryInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class OpsReadinessReportBuilderTest extends TestCase
{
    public function testBuildReturnsOkWhenDatabaseAndStorageChecksPass(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->with('SELECT 1')->willReturn('1');

        $storage = $this->createMock(BusinessStorageInterface::class);
        $storage->method('managedDirectories')->willReturn(['INBOX']);
        $storage->method('directoryExists')->with('INBOX')->willReturn(true);
        $storage->method('probeWritableDirectory')->with('INBOX')->willReturn(true);

        $registry = $this->createMock(BusinessStorageRegistryInterface::class);
        $registry->method('all')->willReturn([new BusinessStorageDefinition('nas-main', $storage)]);

        $payload = (new OpsReadinessReportBuilder($connection, $registry))->build();

        self::assertSame('ok', $payload['status']);
        self::assertFalse($payload['self_healing']['active']);
        self::assertSame('ok', $payload['checks'][0]['status']);
        self::assertSame('ok', $payload['checks'][1]['status']);
        self::assertSame('ok', $payload['checks'][2]['status']);
    }

    public function testBuildReturnsDegradedWhenDirectoryIsMissing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->with('SELECT 1')->willReturn('1');

        $storage = $this->createMock(BusinessStorageInterface::class);
        $storage->method('managedDirectories')->willReturn(['INBOX']);
        $storage->method('directoryExists')->with('INBOX')->willReturn(false);
        $storage->method('probeWritableDirectory')->willReturn(true);

        $registry = $this->createMock(BusinessStorageRegistryInterface::class);
        $registry->method('all')->willReturn([new BusinessStorageDefinition('nas-main', $storage)]);

        $payload = (new OpsReadinessReportBuilder($connection, $registry))->build();

        self::assertSame('degraded', $payload['status']);
        self::assertTrue($payload['self_healing']['active']);
        self::assertSame('fail', $payload['checks'][1]['status']);
    }

    public function testBuildReturnsDownWhenDatabaseCheckFails(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->with('SELECT 1')->willThrowException(new \RuntimeException('db down'));

        $registry = $this->createMock(BusinessStorageRegistryInterface::class);
        $registry->method('all')->willReturn([]);

        $payload = (new OpsReadinessReportBuilder($connection, $registry))->build();

        self::assertSame('down', $payload['status']);
        self::assertSame('fail', $payload['checks'][0]['status']);
        self::assertFalse($payload['self_healing']['active']);
    }

    public function testBuildReturnsDownWhenStorageIsNotWritable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->with('SELECT 1')->willReturn('1');

        $storage = $this->createMock(BusinessStorageInterface::class);
        $storage->method('managedDirectories')->willReturn(['INBOX']);
        $storage->method('directoryExists')->with('INBOX')->willReturn(true);
        $storage->method('probeWritableDirectory')->with('INBOX')->willReturn(false);

        $registry = $this->createMock(BusinessStorageRegistryInterface::class);
        $registry->method('all')->willReturn([new BusinessStorageDefinition('nas-main', $storage)]);

        $payload = (new OpsReadinessReportBuilder($connection, $registry))->build();

        self::assertSame('down', $payload['status']);
        self::assertSame('fail', $payload['checks'][2]['status']);
        self::assertFalse($payload['self_healing']['active']);
    }
}
