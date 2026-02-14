<?php

namespace App\Auth;

use App\Feature\FeatureGovernanceService;

final class AuthClientAdminService
{
    public function __construct(
        private AuthClientStateStore $stateStore,
        private FeatureGovernanceService $featureGovernanceService,
        private ClientAccessTokenFactory $clientAccessTokenFactory,
    ) {
    }

    /**
     * @return array{access_token: string, token_type: string, client_id: string, client_kind: string}|null
     */
    public function mintToken(string $clientId, string $clientKind, string $secretKey): ?array
    {
        $registry = $this->stateStore->registry();
        $client = $registry[$clientId] ?? null;
        if (!is_array($client)) {
            return null;
        }

        if (!hash_equals((string) ($client['secret_key'] ?? ''), $secretKey)) {
            return null;
        }

        if ((string) ($client['client_kind'] ?? '') !== $clientKind) {
            return null;
        }

        $token = $this->clientAccessTokenFactory->issue($clientId, $clientKind);
        $tokens = $this->stateStore->activeTokens();
        $tokens[$clientId] = [
            'access_token' => $token,
            'client_id' => $clientId,
            'client_kind' => $clientKind,
            'issued_at' => time(),
        ];
        $this->stateStore->saveActiveTokens($tokens);

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'client_id' => $clientId,
            'client_kind' => $clientKind,
        ];
    }

    public function isMcpDisabledByAppPolicy(): bool
    {
        $appFeatures = $this->featureGovernanceService->appFeatureEnabled();

        return ($appFeatures['features.ai'] ?? true) === false;
    }

    public function hasClient(string $clientId): bool
    {
        return array_key_exists($clientId, $this->stateStore->registry());
    }

    public function clientKind(string $clientId): ?string
    {
        $registry = $this->stateStore->registry();
        $client = $registry[$clientId] ?? null;
        if (!is_array($client)) {
            return null;
        }

        $clientKind = $client['client_kind'] ?? null;

        return is_string($clientKind) ? $clientKind : null;
    }

    public function revokeToken(string $clientId): bool
    {
        if (!$this->hasClient($clientId)) {
            return false;
        }

        $tokens = $this->stateStore->activeTokens();
        unset($tokens[$clientId]);
        $this->stateStore->saveActiveTokens($tokens);

        return true;
    }

    public function rotateSecret(string $clientId): ?string
    {
        $registry = $this->stateStore->registry();
        $client = $registry[$clientId] ?? null;
        if (!is_array($client)) {
            return null;
        }

        $newSecret = bin2hex(random_bytes(24));
        $client['secret_key'] = $newSecret;
        $registry[$clientId] = $client;
        $this->stateStore->saveRegistry($registry);
        $this->revokeToken($clientId);

        return $newSecret;
    }
}
