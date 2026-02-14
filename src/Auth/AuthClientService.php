<?php

namespace App\Auth;

use App\Feature\FeatureGovernanceService;

final class AuthClientService
{
    public function __construct(
        private AuthClientStateStore $stateStore,
        private FeatureGovernanceService $featureGovernanceService,
        private ClientAccessTokenFactory $clientAccessTokenFactory,
        private AuthClientDeviceFlowService $deviceFlowService,
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

    /**
     * @return array{device_code: string, user_code: string, verification_uri: string, verification_uri_complete: string, expires_in: int, interval: int}
     */
    public function startDeviceFlow(string $clientKind): array
    {
        return $this->deviceFlowService->startDeviceFlow($clientKind);
    }

    /**
     * @return array{status: string, client_id?: string, client_kind?: string, secret_key?: string, interval?: int, retry_in_seconds?: int}|null
     */
    public function pollDeviceFlow(string $deviceCode): ?array
    {
        return $this->deviceFlowService->pollDeviceFlow($deviceCode);
    }

    /**
     * @return array{status: string}|null
     */
    public function cancelDeviceFlow(string $deviceCode): ?array
    {
        return $this->deviceFlowService->cancelDeviceFlow($deviceCode);
    }

    /**
     * @return array{status: string}|null
     */
    public function approveDeviceFlow(string $userCode): ?array
    {
        return $this->deviceFlowService->approveDeviceFlow($userCode);
    }

}
