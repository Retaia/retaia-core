<?php

namespace App\Api\Service\AgentSignature;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AgentPublicKeyStore
{
    private const REGISTRY_KEY = 'signed_agent_public_keys';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function register(string $agentId, string $fingerprint, string $publicKey): void
    {
        $agentId = trim($agentId);
        $fingerprint = $this->normalizeFingerprint($fingerprint);
        $publicKey = trim($publicKey);
        if ($agentId === '' || $fingerprint === null || $publicKey === '') {
            return;
        }

        $registry = $this->registry();
        $registry[$agentId] = [
            'openpgp_fingerprint' => $fingerprint,
            'openpgp_public_key' => $publicKey,
            'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
        ];

        $item = $this->cache->getItem(self::REGISTRY_KEY);
        $item->set($registry);
        $this->cache->save($item);
    }

    public function publicKeyFor(string $agentId, string $fingerprint): ?string
    {
        $agentId = trim($agentId);
        $fingerprint = $this->normalizeFingerprint($fingerprint);
        if ($agentId === '' || $fingerprint === null) {
            return null;
        }

        $registered = $this->registry()[$agentId] ?? null;
        if (!is_array($registered)) {
            return null;
        }

        if (!hash_equals((string) ($registered['openpgp_fingerprint'] ?? ''), $fingerprint)) {
            return null;
        }

        $publicKey = trim((string) ($registered['openpgp_public_key'] ?? ''));

        return $publicKey !== '' ? $publicKey : null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function registry(): array
    {
        $item = $this->cache->getItem(self::REGISTRY_KEY);
        $value = $item->get();

        return is_array($value) ? $value : [];
    }

    private function normalizeFingerprint(string $fingerprint): ?string
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', trim($fingerprint)) ?? '');
        if ($normalized === '' || preg_match('/^[A-F0-9]{40}$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }
}
