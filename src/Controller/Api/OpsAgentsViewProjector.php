<?php

namespace App\Controller\Api;

use App\Api\Service\AgentJobProjectionRepositoryInterface;
use App\Api\Service\AgentRuntimeRepositoryInterface;

final class OpsAgentsViewProjector
{
    private const AGENT_STALE_AFTER_SECONDS = 300;

    public function __construct(
        private AgentRuntimeRepositoryInterface $agentRuntimeRepository,
        private AgentJobProjectionRepositoryInterface $agentJobProjectionRepository,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function paginated(?string $statusFilter, int $limit, int $offset): array
    {
        $items = $this->projectAgents();
        if ($statusFilter !== null && $statusFilter !== '') {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => ($item['status'] ?? null) === $statusFilter
            ));
        }
        usort($items, static function (array $left, array $right): int {
            return strcmp((string) ($right['last_seen_at'] ?? ''), (string) ($left['last_seen_at'] ?? ''));
        });

        return [
            'items' => array_slice($items, $offset, $limit),
            'total' => count($items),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projectAgents(): array
    {
        $entries = $this->agentRuntimeRepository->findAll();
        $jobSnapshots = $this->agentJobProjectionRepository->snapshotsForAgents(array_values(array_filter(array_map(
            static fn (array $entry): string => trim((string) ($entry['agent_id'] ?? '')),
            $entries
        ), static fn (string $agentId): bool => $agentId !== '')));
        $clientUsage = [];
        foreach ($entries as $entry) {
            $clientId = (string) ($entry['client_id'] ?? '');
            if ($clientId === '') {
                continue;
            }
            $clientUsage[$clientId] = ($clientUsage[$clientId] ?? 0) + 1;
        }

        $items = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ($entries as $entry) {
            $agentId = trim((string) ($entry['agent_id'] ?? ''));
            if ($agentId === '') {
                continue;
            }

            $lastSeenAt = $this->atomOrNow($entry['last_seen_at'] ?? null, $now);
            $jobSnapshot = $jobSnapshots[$agentId] ?? [
                'current_job' => null,
                'last_successful_job' => null,
                'last_failed_job' => null,
            ];
            $hasActiveJob = is_array($jobSnapshot['current_job'] ?? null);
            $isStale = ($now->getTimestamp() - $lastSeenAt->getTimestamp()) > self::AGENT_STALE_AFTER_SECONDS;
            $status = $isStale
                ? 'stale'
                : ($hasActiveJob ? 'online_busy' : 'online_idle');

            $clientId = trim((string) ($entry['client_id'] ?? 'unknown'));
            $items[] = [
                'agent_id' => $agentId,
                'client_id' => $clientId,
                'agent_name' => (string) ($entry['agent_name'] ?? ''),
                'agent_version' => (string) ($entry['agent_version'] ?? ''),
                'os_name' => $entry['os_name'] ?? null,
                'os_version' => $entry['os_version'] ?? null,
                'arch' => $entry['arch'] ?? null,
                'status' => $status,
                'identity_conflict' => ($clientUsage[$clientId] ?? 0) > 1,
                'last_seen_at' => $lastSeenAt->format(DATE_ATOM),
                'last_register_at' => $this->atomOrNow($entry['last_register_at'] ?? null, $now)->format(DATE_ATOM),
                'last_heartbeat_at' => $this->atomOrNull($entry['last_heartbeat_at'] ?? null)?->format(DATE_ATOM),
                'effective_capabilities' => array_values(is_array($entry['effective_capabilities'] ?? null) ? $entry['effective_capabilities'] : []),
                'capability_warnings' => array_values(is_array($entry['capability_warnings'] ?? null) ? $entry['capability_warnings'] : []),
                'debug' => [
                    'max_parallel_jobs' => max(1, (int) (($entry['debug']['max_parallel_jobs'] ?? 1))),
                    'feature_flags_contract_version' => $entry['debug']['feature_flags_contract_version'] ?? null,
                    'effective_feature_flags_contract_version' => $entry['debug']['effective_feature_flags_contract_version'] ?? null,
                    'server_time_skew_seconds' => $entry['debug']['server_time_skew_seconds'] ?? null,
                ],
                'current_job' => $jobSnapshot['current_job'] ?? null,
                'last_successful_job' => $jobSnapshot['last_successful_job'] ?? null,
                'last_failed_job' => $jobSnapshot['last_failed_job'] ?? null,
            ];
        }

        return $items;
    }

    private function atomOrNow(mixed $value, \DateTimeImmutable $fallback): \DateTimeImmutable
    {
        try {
            return is_string($value) && $value !== '' ? new \DateTimeImmutable($value) : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function atomOrNull(mixed $value): ?\DateTimeImmutable
    {
        try {
            return is_string($value) && $value !== '' ? new \DateTimeImmutable($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
