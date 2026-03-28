<?php

namespace App\Auth;

final class AuthClientAdminService
{
    public function __construct(
        private AuthClientRegistryRepositoryInterface $registryRepository,
        private TechnicalAccessTokenRepositoryInterface $accessTokenRepository,
        private ClientAccessTokenFactory $clientAccessTokenFactory,
        private AuthClientPolicyService $policyService,
    ) {
    }

    /**
     * @return array{access_token: string, token_type: string, client_id: string, client_kind: string}|null
     */
    public function mintToken(string $clientId, string $clientKind, string $secretKey): ?array
    {
        $client = $this->registryRepository->findByClientId($clientId);
        if (!$client instanceof AuthClientRegistryEntry) {
            return null;
        }

        if (!hash_equals((string) ($client->secretKey ?? ''), $secretKey)) {
            return null;
        }

        if ($client->clientKind !== $clientKind) {
            return null;
        }

        return $this->issueToken($clientId, $clientKind);
    }

    /**
     * @return array{access_token: string, token_type: string, client_id: string, client_kind: string}|null
     */
    public function mintRegisteredClientToken(string $clientId): ?array
    {
        $client = $this->registryRepository->findByClientId($clientId);
        if (!$client instanceof AuthClientRegistryEntry) {
            return null;
        }

        $clientKind = $client->clientKind;
        if ($clientKind === '') {
            return null;
        }

        return $this->issueToken($clientId, $clientKind);
    }

    /**
     * @return array{access_token: string, token_type: string, client_id: string, client_kind: string}
     */
    private function issueToken(string $clientId, string $clientKind): array
    {
        $token = $this->clientAccessTokenFactory->issue($clientId, $clientKind);
        $this->accessTokenRepository->save(new TechnicalAccessTokenRecord($clientId, $token, $clientKind, time()));

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'client_id' => $clientId,
            'client_kind' => $clientKind,
        ];
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
        if (!$this->hasClient($clientId)) {
            return false;
        }

        $this->accessTokenRepository->deleteByClientId($clientId);

        return true;
    }

    public function rotateSecret(string $clientId): ?string
    {
        $client = $this->registryRepository->findByClientId($clientId);
        if (!$client instanceof AuthClientRegistryEntry) {
            return null;
        }

        $newSecret = bin2hex(random_bytes(24));
        $this->registryRepository->save(new AuthClientRegistryEntry(
            $client->clientId,
            $client->clientKind,
            $newSecret,
            $client->clientLabel,
            $client->openPgpPublicKey,
            $client->openPgpFingerprint,
            $client->registeredAt,
            $client->rotatedAt,
        ));
        $this->revokeToken($clientId);

        return $newSecret;
    }
}
