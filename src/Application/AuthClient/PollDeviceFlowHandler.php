<?php

namespace App\Application\AuthClient;

use App\Application\AuthClient\Port\AuthClientGateway;

final class PollDeviceFlowHandler
{
    public function __construct(
        private AuthClientGateway $authClientGateway,
    ) {
    }

    public function handle(string $deviceCode): PollDeviceFlowResult
    {
        $status = $this->authClientGateway->pollDeviceFlow($deviceCode);
        if (!is_array($status)) {
            return new PollDeviceFlowResult(PollDeviceFlowResult::STATUS_INVALID_DEVICE_CODE);
        }

        if (array_key_exists('retry_in_seconds', $status)) {
            return new PollDeviceFlowResult(PollDeviceFlowResult::STATUS_THROTTLED, $status);
        }

        return new PollDeviceFlowResult(PollDeviceFlowResult::STATUS_SUCCESS, $status);
    }
}
