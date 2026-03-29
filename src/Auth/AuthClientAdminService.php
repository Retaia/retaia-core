<?php

namespace App\Auth;

final class AuthClientAdminService
{
    public function __construct(
        private AuthClientRegistryRepositoryInterface $registryRepository,
        private AuthClientTokenMintingService $tokenMintingService,
        private AuthClientSecretRotationService $secretRotationService,
        private AuthClientPolicyService $policyService,
    ) {
    }

    /**
     * @return array{access_token: string, token_type: string, client_id: string, client_kind: string}|null
     */
    public function mintToken(string $clientId, string $clientKind, string $secretKey): ?array
    {
        return $this->tokenMintingService->mintToken($clientId, $clientKind, $secretKey);
    }

    /**
     * @return array{access_token: string, token_type: string, client_id: string, client_kind: string}|null
     */
    public function mintRegisteredClientToken(string $clientId): ?array
    {
        return $this->tokenMintingService->mintRegisteredClientToken($clientId);
    }

    public function isMcpDisabledByAppPolicy(): bool
    {
        return $this->policyService->isMcpDisabledByAppPolicy();
    }

    public function hasClient(string $clientId): bool
    {
        return $this->registryRepository->findByClientId($clientId) instanceof AuthClientRegistryEntry;
    }

    public function clientKind(string $clientId): ?string
    {
        return $this->registryRepository->findByClientId($clientId)?->clientKind;
    }

    public function revokeToken(string $clientId): bool
    {
        return $this->secretRotationService->revokeToken($clientId);
    }

    public function rotateSecret(string $clientId): ?string
    {
        return $this->secretRotationService->rotateSecret($clientId);
    }
}
