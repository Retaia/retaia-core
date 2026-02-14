<?php

namespace App\Application\AuthClient\Port;

interface AuthClientGateway
{
    public function isMcpDisabledByAppPolicy(): bool;

    /**
     * @return array{access_token: string, token_type: string, client_id: string, client_kind: string}|null
     */
    public function mintToken(string $clientId, string $clientKind, string $secretKey): ?array;

    public function hasClient(string $clientId): bool;

    public function clientKind(string $clientId): ?string;

    public function revokeToken(string $clientId): bool;

    public function rotateSecret(string $clientId): ?string;

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
