<?php

namespace App\Application\AuthClient;

use App\Application\AuthClient\Port\AuthClientGateway;

final class CancelDeviceFlowHandler
{
    public function __construct(
        private AuthClientGateway $authClientGateway,
    ) {
    }

    public function handle(string $deviceCode): CancelDeviceFlowResult
    {
        $status = $this->authClientGateway->cancelDeviceFlow($deviceCode);
        if (!is_array($status)) {
            return new CancelDeviceFlowResult(CancelDeviceFlowResult::STATUS_INVALID_DEVICE_CODE);
        }

        if (($status['status'] ?? null) === 'EXPIRED') {
            return new CancelDeviceFlowResult(CancelDeviceFlowResult::STATUS_EXPIRED_DEVICE_CODE);
        }

        return new CancelDeviceFlowResult(CancelDeviceFlowResult::STATUS_SUCCESS);
    }
}
