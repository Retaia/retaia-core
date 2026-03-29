<?php

namespace App\Auth;

final class AuthClientTokenMintingService
{
    public function __construct(
        private AuthClientRegistryRepositoryInterface $registryRepository,
        private TechnicalAccessTokenRepositoryInterface $accessTokenRepository,
        private ClientAccessTokenFactory $clientAccessTokenFactory,
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
}
