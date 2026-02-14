<?php

namespace App\Auth;

use App\Feature\FeatureGovernanceService;
use Psr\Cache\CacheItemPoolInterface;

final class AuthClientService
{
    public function __construct(
        private CacheItemPoolInterface $cache,
        private FeatureGovernanceService $featureGovernanceService,
    ) {
    }

    /**
     * @return array{access_token: string, token_type: string, client_id: string, client_kind: string}|null
     */
    public function mintToken(string $clientId, string $clientKind, string $secretKey): ?array
    {
        $registry = $this->registry();
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

        $token = 'ct_'.bin2hex(random_bytes(24));
        $tokens = $this->activeTokens();
        $tokens[$clientId] = [
            'access_token' => $token,
            'client_id' => $clientId,
            'client_kind' => $clientKind,
            'issued_at' => time(),
        ];
        $this->saveActiveTokens($tokens);

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
        return array_key_exists($clientId, $this->registry());
    }

    public function clientKind(string $clientId): ?string
    {
        $registry = $this->registry();
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

        $tokens = $this->activeTokens();
        unset($tokens[$clientId]);
        $this->saveActiveTokens($tokens);

        return true;
    }

    public function rotateSecret(string $clientId): ?string
    {
        $registry = $this->registry();
        $client = $registry[$clientId] ?? null;
        if (!is_array($client)) {
            return null;
        }

        $newSecret = bin2hex(random_bytes(24));
        $client['secret_key'] = $newSecret;
        $registry[$clientId] = $client;
        $this->saveRegistry($registry);
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

        $flows = $this->deviceFlows();
        $flows[$deviceCode] = $flow;
        $this->saveDeviceFlows($flows);

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
     * @return array{status: string, secret_key?: string, interval?: int, retry_in_seconds?: int}|null
     */
    public function pollDeviceFlow(string $deviceCode): ?array
    {
        $flows = $this->deviceFlows();
        $flow = $flows[$deviceCode] ?? null;
        if (!is_array($flow)) {
            return null;
        }

        $now = time();
        if (($flow['expires_at'] ?? 0) < $now) {
            $flow['status'] = 'EXPIRED';
            $flows[$deviceCode] = $flow;
            $this->saveDeviceFlows($flows);

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

        $flow['last_polled_at'] = $now;
        $flows[$deviceCode] = $flow;
        $this->saveDeviceFlows($flows);

        return ['status' => (string) ($flow['status'] ?? 'PENDING')];
    }

    /**
     * @return array{status: string}|null
     */
    public function cancelDeviceFlow(string $deviceCode): ?array
    {
        $flows = $this->deviceFlows();
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
        $this->saveDeviceFlows($flows);

        return ['status' => 'DENIED'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function registry(): array
    {
        $item = $this->cache->getItem('auth_client_registry');
        $value = $item->get();
        if (is_array($value)) {
            return $value;
        }

        $registry = [
            'agent-default' => [
                'client_kind' => 'AGENT',
                'secret_key' => 'agent-secret',
            ],
            'mcp-default' => [
                'client_kind' => 'MCP',
                'secret_key' => 'mcp-secret',
            ],
        ];
        $item->set($registry);
        $this->cache->save($item);

        return $registry;
    }

    /**
     * @param array<string, array<string, mixed>> $registry
     */
    private function saveRegistry(array $registry): void
    {
        $item = $this->cache->getItem('auth_client_registry');
        $item->set($registry);
        $this->cache->save($item);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function activeTokens(): array
    {
        $item = $this->cache->getItem('auth_client_active_tokens');
        $value = $item->get();

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, array<string, mixed>> $tokens
     */
    private function saveActiveTokens(array $tokens): void
    {
        $item = $this->cache->getItem('auth_client_active_tokens');
        $item->set($tokens);
        $this->cache->save($item);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function deviceFlows(): array
    {
        $item = $this->cache->getItem('auth_device_flows');
        $value = $item->get();

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, array<string, mixed>> $flows
     */
    private function saveDeviceFlows(array $flows): void
    {
        $item = $this->cache->getItem('auth_device_flows');
        $item->set($flows);
        $this->cache->save($item);
    }
}
