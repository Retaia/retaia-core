<?php

namespace App\Application\AuthClient;

use App\Application\AuthClient\Port\DeviceFlowGateway;
use App\Domain\AuthClient\DeviceFlowStatus;

final class CancelDeviceFlowHandler
{
    public function __construct(
        private DeviceFlowGateway $deviceFlowGateway,
    ) {
    }

    public function handle(string $deviceCode): CancelDeviceFlowResult
    {
        $status = $this->deviceFlowGateway->cancelDeviceFlow($deviceCode);
        if (!is_array($status)) {
            return new CancelDeviceFlowResult(CancelDeviceFlowResult::STATUS_INVALID_DEVICE_CODE);
        }

        if (($status['status'] ?? null) === DeviceFlowStatus::EXPIRED) {
            return new CancelDeviceFlowResult(CancelDeviceFlowResult::STATUS_EXPIRED_DEVICE_CODE);
        }

        return new CancelDeviceFlowResult(CancelDeviceFlowResult::STATUS_SUCCESS);
    }
}
