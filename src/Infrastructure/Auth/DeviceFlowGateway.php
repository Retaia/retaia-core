<?php

namespace App\Infrastructure\Auth;

use App\Application\AuthClient\Port\DeviceFlowGateway as DeviceFlowGatewayPort;
use App\Auth\AuthClientDeviceFlowService;
use App\Auth\AuthClientService;

final class DeviceFlowGateway implements DeviceFlowGatewayPort
{
    public function __construct(
        private AuthClientService $authClientService,
        private AuthClientDeviceFlowService $deviceFlowService,
    ) {
    }

    public function isMcpDisabledByAppPolicy(): bool
    {
        return $this->authClientService->isMcpDisabledByAppPolicy();
    }

    public function startDeviceFlow(string $clientKind): array
    {
        return $this->deviceFlowService->startDeviceFlow($clientKind);
    }

    public function pollDeviceFlow(string $deviceCode): ?array
    {
        return $this->deviceFlowService->pollDeviceFlow($deviceCode);
    }

    public function cancelDeviceFlow(string $deviceCode): ?array
    {
        return $this->deviceFlowService->cancelDeviceFlow($deviceCode);
    }

    public function approveDeviceFlow(string $userCode): ?array
    {
        return $this->deviceFlowService->approveDeviceFlow($userCode);
    }
}
