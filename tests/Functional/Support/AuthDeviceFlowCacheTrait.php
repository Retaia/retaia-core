<?php

namespace App\Tests\Functional\Support;

use Doctrine\DBAL\Connection;

trait AuthDeviceFlowCacheTrait
{
    protected function forceDeviceFlowExpiration(string $deviceCode): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $updated = $connection->update('auth_device_flow', ['expires_at' => time() - 1], ['device_code' => $deviceCode]);
        if ($updated < 1) {
            self::fail('Device flow not found in persistence for expiration fixture.');
        }
    }

    protected function forceDeviceFlowLastPolledAt(string $deviceCode, int $lastPolledAt): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $updated = $connection->update('auth_device_flow', ['last_polled_at' => $lastPolledAt], ['device_code' => $deviceCode]);
        if ($updated < 1) {
            self::fail('Device flow not found in persistence for last_polled_at fixture.');
        }
    }
}
