<?php

namespace App\Auth;

final class AuthMcpClientRegistrationService
{
    public function __construct(
        private AuthClientPolicyService $policyService,
        private AuthMcpRegistrationNormalizer $normalizer,
        private AuthMcpClientRegistryService $registryService,
    ) {
    }

    /**
     * @return array{status: string, payload?: array<string, mixed>}
     */
    public function register(string $openPgpPublicKey, string $openPgpFingerprint, ?string $clientLabel = null): array
    {
        if ($this->policyService->isMcpDisabledByAppPolicy()) {
            return ['status' => 'FORBIDDEN_SCOPE'];
        }

        $normalizedFingerprint = $this->normalizer->normalizeFingerprint($openPgpFingerprint);
        $normalizedPublicKey = $this->normalizer->normalizePublicKey($openPgpPublicKey);
        if ($normalizedFingerprint === null || $normalizedPublicKey === null) {
            return ['status' => 'VALIDATION_FAILED'];
        }

        return $this->registryService->register(
            $normalizedPublicKey,
            $normalizedFingerprint,
            $this->normalizer->normalizeLabel($clientLabel),
        );
    }

    /**
     * @return array{status: string, payload?: array<string, mixed>}
     */
    public function rotateKey(string $clientId, string $openPgpPublicKey, string $openPgpFingerprint, ?string $clientLabel = null): array
    {
        if ($this->policyService->isMcpDisabledByAppPolicy()) {
            return ['status' => 'FORBIDDEN_SCOPE'];
        }

        $normalizedFingerprint = $this->normalizer->normalizeFingerprint($openPgpFingerprint);
        $normalizedPublicKey = $this->normalizer->normalizePublicKey($openPgpPublicKey);
        if ($clientId === '' || $normalizedFingerprint === null || $normalizedPublicKey === null) {
            return ['status' => 'VALIDATION_FAILED'];
        }

        return $this->registryService->rotateKey(
            $clientId,
            $normalizedPublicKey,
            $normalizedFingerprint,
            $this->normalizer->normalizeLabel($clientLabel),
        );
    }

    public function mcpClient(string $clientId, string $openPgpFingerprint, bool $checkFingerprint = true): ?AuthClientRegistryEntry
    {
        return $this->registryService->mcpClient($clientId, $openPgpFingerprint, $checkFingerprint);
    }

    public function normalizeFingerprint(string $fingerprint): ?string
    {
        return $this->normalizer->normalizeFingerprint($fingerprint);
    }
}
