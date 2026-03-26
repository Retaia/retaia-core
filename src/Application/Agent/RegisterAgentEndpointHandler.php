<?php

namespace App\Application\Agent;

use App\Api\Service\AgentSignature\AgentPublicKeyStore;
use App\Application\Auth\ResolveAuthenticatedUserResult;
use App\Application\Auth\ResolveAuthenticatedUserUseCase;

final class RegisterAgentEndpointHandler
{
    public function __construct(
        private RegisterAgentUseCase $registerAgentHandler,
        private ResolveAuthenticatedUserUseCase $resolveAuthenticatedUserHandler,
        private AgentPublicKeyStore $agentPublicKeyStore,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): RegisterAgentEndpointResult
    {
        $agentId = trim((string) ($payload['agent_id'] ?? ''));
        $agentName = trim((string) ($payload['agent_name'] ?? ''));
        $agentVersion = trim((string) ($payload['agent_version'] ?? ''));
        $openPgpPublicKey = trim((string) ($payload['openpgp_public_key'] ?? ''));
        $openPgpFingerprint = trim((string) ($payload['openpgp_fingerprint'] ?? ''));
        $osName = trim((string) ($payload['os_name'] ?? ''));
        $osVersion = trim((string) ($payload['os_version'] ?? ''));
        $arch = trim((string) ($payload['arch'] ?? ''));
        $capabilities = $payload['capabilities'] ?? null;
        $clientContractVersion = trim((string) ($payload['client_feature_flags_contract_version'] ?? ''));

        if (
            !$this->isValidAgentId($agentId)
            || $agentName === ''
            || $agentVersion === ''
            || $openPgpPublicKey === ''
            || $openPgpFingerprint === ''
            || !in_array($osName, ['linux', 'macos', 'windows'], true)
            || $osVersion === ''
            || !in_array($arch, ['x86_64', 'arm64', 'armv7', 'other'], true)
            || !is_array($capabilities)
        ) {
            return new RegisterAgentEndpointResult(RegisterAgentEndpointResult::STATUS_VALIDATION_FAILED);
        }

        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        $actorId = $authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_AUTHENTICATED
            ? (string) $authenticatedUser->id()
            : 'unknown';

        $result = $this->registerAgentHandler->handle($actorId, $agentId, $agentName, $clientContractVersion);
        if ($result->status() === RegisterAgentResult::STATUS_UNSUPPORTED_CONTRACT_VERSION) {
            return new RegisterAgentEndpointResult(
                RegisterAgentEndpointResult::STATUS_UNSUPPORTED_CONTRACT_VERSION,
                $result->acceptedFeatureFlagsContractVersions()
            );
        }

        $this->agentPublicKeyStore->register($agentId, $openPgpFingerprint, $openPgpPublicKey);

        return new RegisterAgentEndpointResult(
            RegisterAgentEndpointResult::STATUS_REGISTERED,
            null,
            $result->payload() ?? []
        );
    }

    private function isValidAgentId(string $agentId): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $agentId) === 1;
    }
}
