<?php

namespace App\Infrastructure\Auth;

use App\Application\AuthClient\Port\DeviceFlowGateway as DeviceFlowGatewayPort;
use App\Auth\AuthClientAdminService;
use App\Auth\AuthClientDeviceFlowService;

final class DeviceFlowGateway implements DeviceFlowGatewayPort
{
    public function __construct(
        private AuthClientAdminService $adminService,
        private AuthClientDeviceFlowService $deviceFlowService,
    ) {
    }

    public function isMcpDisabledByAppPolicy(): bool
    {
        return $this->adminService->isMcpDisabledByAppPolicy();
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
