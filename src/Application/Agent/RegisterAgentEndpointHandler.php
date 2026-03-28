<?php

namespace App\Application\Agent;

use App\Api\Service\AgentSignature\AgentPublicKeyRecord;
use App\Api\Service\AgentSignature\AgentPublicKeyRepositoryInterface;
use App\Api\Service\AgentRuntimeRepositoryInterface;
use App\Application\Auth\ResolveAuthenticatedUserResult;
use App\Application\Auth\ResolveAuthenticatedUserUseCase;

final class RegisterAgentEndpointHandler
{
    public function __construct(
        private RegisterAgentUseCase $registerAgentHandler,
        private ResolveAuthenticatedUserUseCase $resolveAuthenticatedUserHandler,
        private AgentPublicKeyRepositoryInterface $agentPublicKeyRepository,
        private AgentRuntimeRepositoryInterface $agentRuntimeRepository,
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
            || !$this->looksLikeAsciiArmoredPublicKey($openPgpPublicKey)
            || !$this->isValidFingerprint($openPgpFingerprint)
            || !in_array($osName, ['linux', 'macos', 'windows'], true)
            || $osVersion === ''
            || !in_array($arch, ['x86_64', 'arm64', 'armv7', 'other'], true)
            || !is_array($capabilities)
            || !$this->hasValidCapabilities($capabilities)
            || !$this->hasValidClientContractVersion($clientContractVersion)
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

        $this->agentPublicKeyRepository->save(new AgentPublicKeyRecord(
            $agentId,
            strtoupper(preg_replace('/\s+/', '', $openPgpFingerprint) ?? ''),
            $openPgpPublicKey,
            time(),
        ));
        $resultPayload = $result->payload() ?? [];
        $serverPolicy = is_array($resultPayload['server_policy'] ?? null) ? $resultPayload['server_policy'] : [];
        $this->agentRuntimeRepository->saveRegistration([
            'agent_id' => $agentId,
            'client_id' => $actorId,
            'agent_name' => $agentName,
            'agent_version' => $agentVersion,
            'os_name' => $osName,
            'os_version' => $osVersion,
            'arch' => $arch,
            'effective_capabilities' => $capabilities,
            'capability_warnings' => [],
            'max_parallel_jobs' => is_int($payload['max_parallel_jobs'] ?? null) ? $payload['max_parallel_jobs'] : 1,
            'feature_flags_contract_version' => $this->nullableString($clientContractVersion),
            'effective_feature_flags_contract_version' => $this->nullableString($serverPolicy['effective_feature_flags_contract_version'] ?? null),
        ]);

        return new RegisterAgentEndpointResult(
            RegisterAgentEndpointResult::STATUS_REGISTERED,
            null,
            $resultPayload + [
                'effective_capabilities' => array_values(array_map(
                    static fn (string $capability): string => trim($capability),
                    array_filter($capabilities, static fn (mixed $capability): bool => is_string($capability) && trim($capability) !== '')
                )),
                'capability_warnings' => [],
            ]
        );
    }

    private function isValidAgentId(string $agentId): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $agentId) === 1;
    }

    private function isValidFingerprint(string $fingerprint): bool
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', trim($fingerprint)) ?? '');

        return preg_match('/^[A-F0-9]{40}$/', $normalized) === 1;
    }

    private function looksLikeAsciiArmoredPublicKey(string $publicKey): bool
    {
        $trimmed = trim($publicKey);

        return str_contains($trimmed, 'BEGIN PGP PUBLIC KEY BLOCK')
            && str_contains($trimmed, 'END PGP PUBLIC KEY BLOCK');
    }

    /**
     * @param array<mixed> $capabilities
     */
    private function hasValidCapabilities(array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if (!is_string($capability) || trim($capability) === '') {
                return false;
            }
        }

        return true;
    }

    private function hasValidClientContractVersion(string $clientContractVersion): bool
    {
        if ($clientContractVersion === '') {
            return true;
        }

        return preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $clientContractVersion) === 1;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
