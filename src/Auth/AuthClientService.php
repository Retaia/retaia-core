<?php

namespace App\Auth;

use App\Feature\FeatureGovernanceService;

final class AuthClientService
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

    /**
     * @return array{device_code: string, user_code: string, verification_uri: string, verification_uri_complete: string, expires_in: int, interval: int}
     */
    public function startDeviceFlow(string $clientKind): array
    {
        $deviceCode = 'dc_'.bin2hex(random_bytes(12));
        $userCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $now = time();
        $flow = [
            'device_code' => $deviceCode,
            'user_code' => $userCode,
            'client_kind' => $clientKind,
            'status' => 'PENDING',
            'created_at' => $now,
            'expires_at' => $now + 600,
            'interval' => 5,
            'last_polled_at' => 0,
        ];

        $flows = $this->stateStore->deviceFlows();
        $flows[$deviceCode] = $flow;
        $this->stateStore->saveDeviceFlows($flows);

        return [
            'device_code' => $deviceCode,
            'user_code' => $userCode,
            'verification_uri' => '/device',
            'verification_uri_complete' => '/device?user_code='.$userCode,
            'expires_in' => 600,
            'interval' => 5,
        ];
    }

    /**
     * @return array{status: string, client_id?: string, client_kind?: string, secret_key?: string, interval?: int, retry_in_seconds?: int}|null
     */
    public function pollDeviceFlow(string $deviceCode): ?array
    {
        $flows = $this->stateStore->deviceFlows();
        $flow = $flows[$deviceCode] ?? null;
        if (!is_array($flow)) {
            return null;
        }

        $now = time();
        if (($flow['expires_at'] ?? 0) < $now) {
            $flow['status'] = 'EXPIRED';
            $flows[$deviceCode] = $flow;
            $this->stateStore->saveDeviceFlows($flows);

            return ['status' => 'EXPIRED'];
        }

        $interval = (int) ($flow['interval'] ?? 5);
        $lastPolledAt = (int) ($flow['last_polled_at'] ?? 0);
        if ($lastPolledAt > 0 && ($now - $lastPolledAt) < $interval) {
            return [
                'status' => 'PENDING',
                'interval' => $interval,
                'retry_in_seconds' => max(1, $interval - ($now - $lastPolledAt)),
            ];
        }

        $status = (string) ($flow['status'] ?? 'PENDING');
        if ($status === 'APPROVED') {
            $clientId = (string) ($flow['approved_client_id'] ?? '');
            $clientKind = (string) ($flow['client_kind'] ?? '');
            $secretKey = (string) ($flow['approved_secret_key'] ?? '');
            if ($clientId !== '' && $clientKind !== '' && $secretKey !== '') {
                unset($flows[$deviceCode]);
                $this->stateStore->saveDeviceFlows($flows);

                return [
                    'status' => 'APPROVED',
                    'client_id' => $clientId,
                    'client_kind' => $clientKind,
                    'secret_key' => $secretKey,
                ];
            }
        }

        $flow['last_polled_at'] = $now;
        $flows[$deviceCode] = $flow;
        $this->stateStore->saveDeviceFlows($flows);

        return ['status' => $status];
    }

    /**
     * @return array{status: string}|null
     */
    public function cancelDeviceFlow(string $deviceCode): ?array
    {
        $flows = $this->stateStore->deviceFlows();
        $flow = $flows[$deviceCode] ?? null;
        if (!is_array($flow)) {
            return null;
        }

        $now = time();
        if (($flow['expires_at'] ?? 0) < $now) {
            return ['status' => 'EXPIRED'];
        }

        $flow['status'] = 'DENIED';
        $flows[$deviceCode] = $flow;
        $this->stateStore->saveDeviceFlows($flows);

        return ['status' => 'DENIED'];
    }

    /**
     * @return array{status: string}|null
     */
    public function approveDeviceFlow(string $userCode): ?array
    {
        $normalizedUserCode = strtoupper(trim($userCode));
        if ($normalizedUserCode === '') {
            return null;
        }

        $flows = $this->stateStore->deviceFlows();
        $matchedKey = null;
        $matchedFlow = null;
        foreach ($flows as $deviceCode => $flow) {
            if (!is_array($flow)) {
                continue;
            }
            if (strtoupper((string) ($flow['user_code'] ?? '')) !== $normalizedUserCode) {
                continue;
            }
            $matchedKey = is_string($deviceCode) ? $deviceCode : null;
            $matchedFlow = $flow;
            break;
        }

        if ($matchedKey === null || !is_array($matchedFlow)) {
            return null;
        }

        $now = time();
        if (($matchedFlow['expires_at'] ?? 0) < $now) {
            $matchedFlow['status'] = 'EXPIRED';
            $flows[$matchedKey] = $matchedFlow;
            $this->stateStore->saveDeviceFlows($flows);

            return ['status' => 'EXPIRED'];
        }

        if (($matchedFlow['status'] ?? '') === 'DENIED') {
            return ['status' => 'DENIED'];
        }
        if (($matchedFlow['status'] ?? '') === 'APPROVED') {
            return ['status' => 'APPROVED'];
        }

        $clientKind = (string) ($matchedFlow['client_kind'] ?? '');
        if (!in_array($clientKind, ['AGENT', 'MCP'], true)) {
            return null;
        }

        $clientId = strtolower($clientKind).'-'.bin2hex(random_bytes(6));
        $secretKey = bin2hex(random_bytes(24));
        $registry = $this->stateStore->registry();
        $registry[$clientId] = [
            'client_kind' => $clientKind,
            'secret_key' => $secretKey,
        ];
        $this->stateStore->saveRegistry($registry);

        $matchedFlow['status'] = 'APPROVED';
        $matchedFlow['approved_client_id'] = $clientId;
        $matchedFlow['approved_secret_key'] = $secretKey;
        $flows[$matchedKey] = $matchedFlow;
        $this->stateStore->saveDeviceFlows($flows);

        return ['status' => 'APPROVED'];
    }

}
