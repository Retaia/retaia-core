<?php

namespace App\Auth;

use App\Domain\AuthClient\ClientKind;
use Psr\Cache\CacheItemPoolInterface;

final class AuthClientStateStore
{
    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function registry(): array
    {
        $item = $this->cache->getItem('auth_client_registry');
        $value = $item->get();
        if (is_array($value)) {
            return $value;
        }

        $registry = [
            'agent-default' => [
                'client_kind' => ClientKind::AGENT,
                'secret_key' => 'agent-secret',
            ],
            'mcp-default' => [
                'client_kind' => ClientKind::MCP,
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
    public function saveRegistry(array $registry): void
    {
        $item = $this->cache->getItem('auth_client_registry');
        $item->set($registry);
        $this->cache->save($item);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function activeTokens(): array
    {
        $item = $this->cache->getItem('auth_client_active_tokens');
        $value = $item->get();

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, array<string, mixed>> $tokens
     */
    public function saveActiveTokens(array $tokens): void
    {
        $item = $this->cache->getItem('auth_client_active_tokens');
        $item->set($tokens);
        $this->cache->save($item);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function deviceFlows(): array
    {
        $item = $this->cache->getItem('auth_device_flows');
        $value = $item->get();

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, array<string, mixed>> $flows
     */
    public function saveDeviceFlows(array $flows): void
    {
        $item = $this->cache->getItem('auth_device_flows');
        $item->set($flows);
        $this->cache->save($item);
    }
}
