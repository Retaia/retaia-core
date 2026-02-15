<?php

namespace App\Application\Agent;

use App\Application\Auth\ResolveAuthenticatedUserResult;
use App\Application\Auth\ResolveAuthenticatedUserUseCase;

final class RegisterAgentEndpointHandler
{
    public function __construct(
        private RegisterAgentUseCase $registerAgentHandler,
        private ResolveAuthenticatedUserUseCase $resolveAuthenticatedUserHandler,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): RegisterAgentEndpointResult
    {
        $agentName = trim((string) ($payload['agent_name'] ?? ''));
        $agentVersion = trim((string) ($payload['agent_version'] ?? ''));
        $capabilities = $payload['capabilities'] ?? null;
        $clientContractVersion = trim((string) ($payload['client_feature_flags_contract_version'] ?? ''));

        if ($agentName === '' || $agentVersion === '' || !is_array($capabilities)) {
            return new RegisterAgentEndpointResult(RegisterAgentEndpointResult::STATUS_VALIDATION_FAILED);
        }

        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        $actorId = $authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_AUTHENTICATED
            ? (string) $authenticatedUser->id()
            : 'unknown';

        $result = $this->registerAgentHandler->handle($actorId, $agentName, $clientContractVersion);
        if ($result->status() === RegisterAgentResult::STATUS_UNSUPPORTED_CONTRACT_VERSION) {
            return new RegisterAgentEndpointResult(
                RegisterAgentEndpointResult::STATUS_UNSUPPORTED_CONTRACT_VERSION,
                $result->acceptedFeatureFlagsContractVersions()
            );
        }

        return new RegisterAgentEndpointResult(
            RegisterAgentEndpointResult::STATUS_REGISTERED,
            null,
            $result->payload() ?? []
        );
    }
}
