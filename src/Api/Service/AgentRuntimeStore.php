<?php

namespace App\Api\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AgentRuntimeStore
{
    private const REGISTRY_KEY = 'agent_runtime_registry';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function register(array $entry): void
    {
        $agentId = trim((string) ($entry['agent_id'] ?? ''));
        if ($agentId === '') {
            return;
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $registry = $this->registry();
        $previous = is_array($registry[$agentId] ?? null) ? $registry[$agentId] : [];

        $registry[$agentId] = [
            'agent_id' => $agentId,
            'client_id' => trim((string) ($entry['client_id'] ?? ($previous['client_id'] ?? 'unknown'))),
            'agent_name' => trim((string) ($entry['agent_name'] ?? ($previous['agent_name'] ?? ''))),
            'agent_version' => trim((string) ($entry['agent_version'] ?? ($previous['agent_version'] ?? ''))),
            'os_name' => $this->nullableString($entry['os_name'] ?? ($previous['os_name'] ?? null)),
            'os_version' => $this->nullableString($entry['os_version'] ?? ($previous['os_version'] ?? null)),
            'arch' => $this->nullableString($entry['arch'] ?? ($previous['arch'] ?? null)),
            'effective_capabilities' => $this->stringList($entry['effective_capabilities'] ?? ($previous['effective_capabilities'] ?? [])),
            'capability_warnings' => $this->stringList($entry['capability_warnings'] ?? ($previous['capability_warnings'] ?? [])),
            'last_register_at' => $now,
            'last_seen_at' => $now,
            'last_heartbeat_at' => $this->nullableString($previous['last_heartbeat_at'] ?? null),
            'debug' => [
                'max_parallel_jobs' => $this->positiveInt($entry['max_parallel_jobs'] ?? ($previous['debug']['max_parallel_jobs'] ?? 1)),
                'feature_flags_contract_version' => $this->nullableString($entry['feature_flags_contract_version'] ?? ($previous['debug']['feature_flags_contract_version'] ?? null)),
                'effective_feature_flags_contract_version' => $this->nullableString($entry['effective_feature_flags_contract_version'] ?? ($previous['debug']['effective_feature_flags_contract_version'] ?? null)),
                'server_time_skew_seconds' => $this->nullableInt($entry['server_time_skew_seconds'] ?? ($previous['debug']['server_time_skew_seconds'] ?? null)),
            ],
        ];

        $item = $this->cache->getItem(self::REGISTRY_KEY);
        $item->set($registry);
        $this->cache->save($item);
    }

    public function touchSeen(string $agentId): void
    {
        $this->touch($agentId, false);
    }

    public function touchHeartbeat(string $agentId): void
    {
        $this->touch($agentId, true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return array_values($this->registry());
    }

    private function touch(string $agentId, bool $heartbeat): void
    {
        $agentId = trim($agentId);
        if ($agentId === '') {
            return;
        }

        $registry = $this->registry();
        $entry = $registry[$agentId] ?? null;
        if (!is_array($entry)) {
            return;
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $entry['last_seen_at'] = $now;
        if ($heartbeat) {
            $entry['last_heartbeat_at'] = $now;
        }
        $registry[$agentId] = $entry;

        $item = $this->cache->getItem(self::REGISTRY_KEY);
        $item->set($registry);
        $this->cache->save($item);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function registry(): array
    {
        $item = $this->cache->getItem(self::REGISTRY_KEY);
        $value = $item->get();

        return is_array($value) ? $value : [];
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $normalized[] = $item;
        }

        return array_values(array_unique($normalized));
    }

    private function positiveInt(mixed $value): int
    {
        return is_int($value) && $value >= 1 ? $value : 1;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
