<?php

namespace App\Api\Service;

use Doctrine\DBAL\Connection;

final class AgentRuntimeWriter
{
    public function __construct(
        private Connection $connection,
        private AgentRuntimeProjector $projector,
        private AgentRuntimeRowMapper $rowMapper,
    ) {
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function saveRegistration(array $entry): void
    {
        $agentId = trim((string) ($entry['agent_id'] ?? ''));
        if ($agentId === '') {
            return;
        }

        $now = $this->now();
        $previous = $this->projector->findOne($agentId);
        $row = [
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

        if ($previous === null) {
            $this->connection->insert('agent_runtime', $this->rowMapper->toPersistenceRow($row));

            return;
        }

        $this->connection->update('agent_runtime', $this->rowMapper->toPersistenceRow($row), ['agent_id' => $agentId]);
    }

    public function touchSeen(string $agentId): void
    {
        $this->touch($agentId, false);
    }

    public function touchHeartbeat(string $agentId): void
    {
        $this->touch($agentId, true);
    }

    private function touch(string $agentId, bool $heartbeat): void
    {
        $agentId = trim($agentId);
        if ($agentId === '') {
            return;
        }

        $entry = $this->projector->findOne($agentId);
        if ($entry === null) {
            return;
        }

        $entry['last_seen_at'] = $this->now();
        if ($heartbeat) {
            $entry['last_heartbeat_at'] = $entry['last_seen_at'];
        }

        $this->connection->update('agent_runtime', $this->rowMapper->toPersistenceRow($entry), ['agent_id' => $agentId]);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
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
        if (is_numeric($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 1 ? $value : 1;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
