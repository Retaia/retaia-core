<?php

namespace App\Auth;

final class AuthClientService
{
    public function __construct(
        private AuthClientAdminService $adminService,
        private AuthClientDeviceFlowService $deviceFlowService,
    ) {
    }

    /**
     * @return array{access_token: string, token_type: string, client_id: string, client_kind: string}|null
     */
    public function mintToken(string $clientId, string $clientKind, string $secretKey): ?array
    {
        return $this->adminService->mintToken($clientId, $clientKind, $secretKey);
    }

    public function isMcpDisabledByAppPolicy(): bool
    {
        return $this->adminService->isMcpDisabledByAppPolicy();
    }

    public function hasClient(string $clientId): bool
    {
        return $this->adminService->hasClient($clientId);
    }

    public function clientKind(string $clientId): ?string
    {
        return $this->adminService->clientKind($clientId);
    }

    public function revokeToken(string $clientId): bool
    {
        return $this->adminService->revokeToken($clientId);
    }

    public function rotateSecret(string $clientId): ?string
    {
        return $this->adminService->rotateSecret($clientId);
    }

    /**
     * @return array{device_code: string, user_code: string, verification_uri: string, verification_uri_complete: string, expires_in: int, interval: int}
     */
    public function startDeviceFlow(string $clientKind): array
    {
        return $this->deviceFlowService->startDeviceFlow($clientKind);
    }

    /**
     * @return array{status: string, client_id?: string, client_kind?: string, secret_key?: string, interval?: int, retry_in_seconds?: int}|null
     */
    public function pollDeviceFlow(string $deviceCode): ?array
    {
        return $this->deviceFlowService->pollDeviceFlow($deviceCode);
    }

    /**
     * @return array{status: string}|null
     */
    public function cancelDeviceFlow(string $deviceCode): ?array
    {
        return $this->deviceFlowService->cancelDeviceFlow($deviceCode);
    }

    /**
     * @return array{status: string}|null
     */
    public function approveDeviceFlow(string $userCode): ?array
    {
        return $this->deviceFlowService->approveDeviceFlow($userCode);
    }

}
