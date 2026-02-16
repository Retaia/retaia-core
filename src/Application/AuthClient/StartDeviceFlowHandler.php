<?php

namespace App\Application\AuthClient;

use App\Application\AuthClient\Port\DeviceFlowGateway;
use App\Domain\AuthClient\ClientKind;
use App\Domain\AuthClient\TechnicalClientTokenPolicy;

final class StartDeviceFlowHandler
{
    public function __construct(
        private TechnicalClientTokenPolicy $policy,
        private DeviceFlowGateway $deviceFlowGateway,
    ) {
    }

    public function handle(string $clientKind): StartDeviceFlowResult
    {
        if (!ClientKind::isTechnical($clientKind)) {
            return new StartDeviceFlowResult(StartDeviceFlowResult::STATUS_FORBIDDEN_ACTOR);
        }

        if ($this->policy->isForbiddenActor($clientKind)) {
            return new StartDeviceFlowResult(StartDeviceFlowResult::STATUS_FORBIDDEN_ACTOR);
        }

        if ($this->policy->isForbiddenScope($clientKind, $this->deviceFlowGateway->isMcpDisabledByAppPolicy())) {
            return new StartDeviceFlowResult(StartDeviceFlowResult::STATUS_FORBIDDEN_SCOPE);
        }

        $payload = $this->deviceFlowGateway->startDeviceFlow($clientKind);

        return new StartDeviceFlowResult(StartDeviceFlowResult::STATUS_SUCCESS, $payload);
    }
}
