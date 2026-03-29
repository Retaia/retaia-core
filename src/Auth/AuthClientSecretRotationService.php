<?php

namespace App\Auth;

final class AuthClientSecretRotationService
{
    public function __construct(
        private AuthClientRegistryRepositoryInterface $registryRepository,
        private TechnicalAccessTokenRepositoryInterface $accessTokenRepository,
    ) {
    }

    public function revokeToken(string $clientId): bool
    {
        if (!$this->registryRepository->findByClientId($clientId) instanceof AuthClientRegistryEntry) {
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
        $this->accessTokenRepository->deleteByClientId($clientId);

        return $newSecret;
    }
}
