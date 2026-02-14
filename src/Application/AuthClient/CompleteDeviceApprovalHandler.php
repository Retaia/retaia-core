<?php

namespace App\Application\AuthClient;

use App\Application\AuthClient\Port\DeviceApprovalSecondFactorGateway;

final class CompleteDeviceApprovalHandler
{
    public function __construct(
        private DeviceApprovalSecondFactorGateway $secondFactorGateway,
        private ApproveDeviceFlowHandler $approveDeviceFlowHandler,
    ) {
    }

    public function handle(string $userId, string $userCode, string $otpCode): CompleteDeviceApprovalResult
    {
        if ($this->secondFactorGateway->isEnabled($userId)) {
            if (trim($otpCode) === '') {
                return new CompleteDeviceApprovalResult(CompleteDeviceApprovalResult::STATUS_VALIDATION_FAILED_OTP_REQUIRED);
            }

            if (!$this->secondFactorGateway->verifyLoginOtp($userId, $otpCode)) {
                return new CompleteDeviceApprovalResult(CompleteDeviceApprovalResult::STATUS_INVALID_2FA_CODE);
            }
        }

        $approval = $this->approveDeviceFlowHandler->handle($userCode);
        if ($approval->status() === ApproveDeviceFlowResult::STATUS_INVALID_DEVICE_CODE) {
            return new CompleteDeviceApprovalResult(CompleteDeviceApprovalResult::STATUS_INVALID_DEVICE_CODE);
        }
        if ($approval->status() === ApproveDeviceFlowResult::STATUS_EXPIRED_DEVICE_CODE) {
            return new CompleteDeviceApprovalResult(CompleteDeviceApprovalResult::STATUS_EXPIRED_DEVICE_CODE);
        }
        if ($approval->status() === ApproveDeviceFlowResult::STATUS_STATE_CONFLICT) {
            return new CompleteDeviceApprovalResult(CompleteDeviceApprovalResult::STATUS_STATE_CONFLICT);
        }

        return new CompleteDeviceApprovalResult(CompleteDeviceApprovalResult::STATUS_SUCCESS);
    }
}
