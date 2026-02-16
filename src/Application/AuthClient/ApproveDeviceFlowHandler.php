<?php

namespace App\Application\AuthClient;

use App\Application\AuthClient\Port\DeviceFlowGateway;
use App\Domain\AuthClient\DeviceFlowStatus;

final class ApproveDeviceFlowHandler
{
    public function __construct(
        private DeviceFlowGateway $deviceFlowGateway,
    ) {
    }

    public function handle(string $userCode): ApproveDeviceFlowResult
    {
        $status = $this->deviceFlowGateway->approveDeviceFlow($userCode);
        if (!is_array($status)) {
            return new ApproveDeviceFlowResult(ApproveDeviceFlowResult::STATUS_INVALID_DEVICE_CODE);
        }

        if (($status['status'] ?? null) === DeviceFlowStatus::EXPIRED) {
            return new ApproveDeviceFlowResult(ApproveDeviceFlowResult::STATUS_EXPIRED_DEVICE_CODE);
        }

        if (($status['status'] ?? null) !== DeviceFlowStatus::APPROVED) {
            return new ApproveDeviceFlowResult(ApproveDeviceFlowResult::STATUS_STATE_CONFLICT);
        }

        return new ApproveDeviceFlowResult(ApproveDeviceFlowResult::STATUS_SUCCESS);
    }
}
