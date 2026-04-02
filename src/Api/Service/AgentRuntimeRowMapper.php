<?php

namespace App\Api\Service;

final class AgentRuntimeRowMapper
{
    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public function toPersistenceRow(array $entry): array
    {
        return [
            'agent_id' => $entry['agent_id'],
            'client_id' => $entry['client_id'],
            'agent_name' => $entry['agent_name'],
            'agent_version' => $entry['agent_version'],
            'os_name' => $entry['os_name'],
            'os_version' => $entry['os_version'],
            'arch' => $entry['arch'],
            'effective_capabilities' => json_encode($entry['effective_capabilities'] ?? [], JSON_THROW_ON_ERROR),
            'capability_warnings' => json_encode($entry['capability_warnings'] ?? [], JSON_THROW_ON_ERROR),
            'last_register_at' => $entry['last_register_at'],
            'last_seen_at' => $entry['last_seen_at'],
            'last_heartbeat_at' => $entry['last_heartbeat_at'],
            'max_parallel_jobs' => $entry['debug']['max_parallel_jobs'] ?? 1,
            'feature_flags_contract_version' => $entry['debug']['feature_flags_contract_version'] ?? null,
            'effective_feature_flags_contract_version' => $entry['debug']['effective_feature_flags_contract_version'] ?? null,
            'server_time_skew_seconds' => $entry['debug']['server_time_skew_seconds'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function fromPersistenceRow(array $row): array
    {
        return [
            'agent_id' => (string) ($row['agent_id'] ?? ''),
            'client_id' => (string) ($row['client_id'] ?? 'unknown'),
            'agent_name' => (string) ($row['agent_name'] ?? ''),
            'agent_version' => (string) ($row['agent_version'] ?? ''),
            'os_name' => $this->nullableString($row['os_name'] ?? null),
            'os_version' => $this->nullableString($row['os_version'] ?? null),
            'arch' => $this->nullableString($row['arch'] ?? null),
            'effective_capabilities' => $this->decodeStringList($row['effective_capabilities'] ?? null),
            'capability_warnings' => $this->decodeStringList($row['capability_warnings'] ?? null),
            'last_register_at' => $this->nullableString($row['last_register_at'] ?? null) ?? $this->now(),
            'last_seen_at' => $this->nullableString($row['last_seen_at'] ?? null) ?? $this->now(),
            'last_heartbeat_at' => $this->nullableString($row['last_heartbeat_at'] ?? null),
            'debug' => [
                'max_parallel_jobs' => $this->positiveInt($row['max_parallel_jobs'] ?? 1),
                'feature_flags_contract_version' => $this->nullableString($row['feature_flags_contract_version'] ?? null),
                'effective_feature_flags_contract_version' => $this->nullableString($row['effective_feature_flags_contract_version'] ?? null),
                'server_time_skew_seconds' => $this->nullableInt($row['server_time_skew_seconds'] ?? null),
            ],
        ];
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

    /**
     * @return array<int, string>
     */
    private function decodeStringList(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return $this->stringList($decoded);
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
