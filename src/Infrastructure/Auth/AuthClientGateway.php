<?php

namespace App\Infrastructure\Auth;

use App\Application\AuthClient\Port\AuthClientGateway as AuthClientGatewayPort;
use App\Application\AuthClient\Port\DeviceFlowGateway as DeviceFlowGatewayPort;
use App\Auth\AuthClientService;

final class AuthClientGateway implements AuthClientGatewayPort, DeviceFlowGatewayPort
{
    public function __construct(
        private AuthClientService $authClientService,
    ) {
    }

    public function isMcpDisabledByAppPolicy(): bool
    {
        return $this->authClientService->isMcpDisabledByAppPolicy();
    }

    /**
     * @return array{access_token: string, token_type: string, client_id: string, client_kind: string}|null
     */
    public function mintToken(string $clientId, string $clientKind, string $secretKey): ?array
    {
        return $this->authClientService->mintToken($clientId, $clientKind, $secretKey);
    }

    public function hasClient(string $clientId): bool
    {
        return $this->authClientService->hasClient($clientId);
    }

    public function clientKind(string $clientId): ?string
    {
        return $this->authClientService->clientKind($clientId);
    }

    public function revokeToken(string $clientId): bool
    {
        return $this->authClientService->revokeToken($clientId);
    }

    public function rotateSecret(string $clientId): ?string
    {
        return $this->authClientService->rotateSecret($clientId);
    }

    public function startDeviceFlow(string $clientKind): array
    {
        return $this->authClientService->startDeviceFlow($clientKind);
    }

    public function pollDeviceFlow(string $deviceCode): ?array
    {
        return $this->authClientService->pollDeviceFlow($deviceCode);
    }

    public function cancelDeviceFlow(string $deviceCode): ?array
    {
        return $this->authClientService->cancelDeviceFlow($deviceCode);
    }

    public function approveDeviceFlow(string $userCode): ?array
    {
        return $this->authClientService->approveDeviceFlow($userCode);
    }
}
