<?php

namespace App\Application\AuthClient\Port;

interface DeviceFlowGateway
{
    public function isMcpDisabledByAppPolicy(): bool;

    /**
     * @return array{device_code: string, user_code: string, verification_uri: string, verification_uri_complete: string, expires_in: int, interval: int}
     */
    public function startDeviceFlow(string $clientKind): array;

    /**
     * @return array{status: string, client_id?: string, client_kind?: string, secret_key?: string, interval?: int, retry_in_seconds?: int}|null
     */
    public function pollDeviceFlow(string $deviceCode): ?array;

    /**
     * @return array{status: string}|null
     */
    public function cancelDeviceFlow(string $deviceCode): ?array;

    /**
     * @return array{status: string}|null
     */
    public function approveDeviceFlow(string $userCode): ?array;
}
