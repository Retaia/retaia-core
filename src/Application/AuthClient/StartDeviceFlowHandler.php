<?php

namespace App\Application\AuthClient;

use App\Application\AuthClient\Port\AuthClientGateway;
use App\Domain\AuthClient\TechnicalClientTokenPolicy;

final class StartDeviceFlowHandler
{
    public function __construct(
        private TechnicalClientTokenPolicy $policy,
        private AuthClientGateway $authClientGateway,
    ) {
    }

    public function handle(string $clientKind): StartDeviceFlowResult
    {
        if (!in_array($clientKind, ['AGENT', 'MCP'], true)) {
            return new StartDeviceFlowResult(StartDeviceFlowResult::STATUS_FORBIDDEN_ACTOR);
        }

        if ($this->policy->isForbiddenActor($clientKind)) {
            return new StartDeviceFlowResult(StartDeviceFlowResult::STATUS_FORBIDDEN_ACTOR);
        }

        if ($this->policy->isForbiddenScope($clientKind, $this->authClientGateway->isMcpDisabledByAppPolicy())) {
            return new StartDeviceFlowResult(StartDeviceFlowResult::STATUS_FORBIDDEN_SCOPE);
        }

        $payload = $this->authClientGateway->startDeviceFlow($clientKind);

        return new StartDeviceFlowResult(StartDeviceFlowResult::STATUS_SUCCESS, $payload);
    }
}
