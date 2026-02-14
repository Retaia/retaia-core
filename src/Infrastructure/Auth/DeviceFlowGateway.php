<?php

namespace App\Infrastructure\Auth;

use App\Application\AuthClient\Port\DeviceFlowGateway as DeviceFlowGatewayPort;
use App\Auth\AuthClientService;

final class DeviceFlowGateway implements DeviceFlowGatewayPort
{
    public function __construct(
        private AuthClientService $authClientService,
    ) {
    }

    public function isMcpDisabledByAppPolicy(): bool
    {
        return $this->authClientService->isMcpDisabledByAppPolicy();
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
