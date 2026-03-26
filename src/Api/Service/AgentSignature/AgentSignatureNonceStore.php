<?php

namespace App\Api\Service\AgentSignature;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AgentSignatureNonceStore
{
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function consume(string $agentId, string $nonce, int $ttlSeconds): bool
    {
        $agentId = trim($agentId);
        $nonce = trim($nonce);
        if ($agentId === '' || $nonce === '' || $ttlSeconds < 1) {
            return false;
        }

        $item = $this->cache->getItem($this->cacheKey($agentId, $nonce));
        if ($item->isHit()) {
            return false;
        }

        $item->set(time());
        $item->expiresAfter($ttlSeconds);
        $this->cache->save($item);

        return true;
    }

    private function cacheKey(string $agentId, string $nonce): string
    {
        return 'signed_agent_nonce_'.hash('sha256', $agentId.'|'.$nonce);
    }
}
