<?php

namespace App\Auth;

use Doctrine\DBAL\Connection;

final class AuthDeviceFlowRepository implements AuthDeviceFlowRepositoryInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function findByDeviceCode(string $deviceCode): ?AuthDeviceFlow
    {
        $row = $this->connection->fetchAssociative(
            'SELECT device_code, user_code, client_kind, status, created_at, expires_at, interval_seconds, last_polled_at, approved_client_id, approved_secret_key
             FROM auth_device_flow WHERE device_code = :deviceCode LIMIT 1',
            ['deviceCode' => $deviceCode]
        );

        return is_array($row) ? AuthDeviceFlow::fromArray($row) : null;
    }

    public function findByUserCode(string $userCode): ?AuthDeviceFlow
    {
        $row = $this->connection->fetchAssociative(
            'SELECT device_code, user_code, client_kind, status, created_at, expires_at, interval_seconds, last_polled_at, approved_client_id, approved_secret_key
             FROM auth_device_flow WHERE user_code = :userCode LIMIT 1',
            ['userCode' => strtoupper(trim($userCode))]
        );

        return is_array($row) ? AuthDeviceFlow::fromArray($row) : null;
    }

    public function save(AuthDeviceFlow $flow): void
    {
        $data = $flow->toRow();
        if ($this->findByDeviceCode($flow->deviceCode) !== null) {
            $this->connection->update('auth_device_flow', $data, ['device_code' => $flow->deviceCode]);
            return;
        }

        $this->connection->insert('auth_device_flow', $data);
    }

    public function delete(string $deviceCode): void
    {
        $this->connection->delete('auth_device_flow', ['device_code' => trim($deviceCode)]);
    }
}
