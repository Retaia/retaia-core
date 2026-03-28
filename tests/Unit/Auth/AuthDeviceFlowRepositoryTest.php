<?php

namespace App\Tests\Unit\Auth;

use App\Auth\AuthDeviceFlow;
use App\Auth\AuthDeviceFlowRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AuthDeviceFlowRepositoryTest extends TestCase
{
    public function testSaveAndQueryByDeviceCodeAndUserCode(): void
    {
        $repository = new AuthDeviceFlowRepository($this->connection());
        $flow = new AuthDeviceFlow('dc_1', 'ABCD1234', 'AGENT', 'PENDING', 10, 20, 5, 0, null, null);

        $repository->save($flow);

        self::assertSame('dc_1', $repository->findByDeviceCode('dc_1')?->deviceCode);
        self::assertSame('ABCD1234', $repository->findByUserCode('abcd1234')?->userCode);

        $repository->delete('dc_1');
        self::assertNull($repository->findByDeviceCode('dc_1'));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE auth_device_flow (device_code VARCHAR(32) PRIMARY KEY NOT NULL, user_code VARCHAR(16) NOT NULL, client_kind VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, interval_seconds INTEGER NOT NULL, last_polled_at INTEGER NOT NULL, approved_client_id VARCHAR(64) DEFAULT NULL, approved_secret_key VARCHAR(128) DEFAULT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX uniq_auth_device_flow_user_code ON auth_device_flow (user_code)');

        return $connection;
    }
}
