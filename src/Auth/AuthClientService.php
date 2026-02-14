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
}
