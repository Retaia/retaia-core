<?php

namespace App\Tests\Unit\Observability;

use App\Observability\Repository\MetricEventRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class MetricEventRepositoryTest extends TestCase
{
    public function testRecordSwallowsInsertFailuresAndCountSinceDelegates(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->willThrowException(new \RuntimeException('boom'));
        $connection->expects(self::once())->method('fetchOne')->willReturn('3');

        $repository = new MetricEventRepository($connection);

        $repository->record('metric.key');
        self::assertSame(3, $repository->countSince('metric.key', new \DateTimeImmutable('-1 hour')));
    }
}
