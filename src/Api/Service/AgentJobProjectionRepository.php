<?php

namespace App\Api\Service;

use Doctrine\DBAL\Connection;

final class AgentJobProjectionRepository implements AgentJobProjectionRepositoryInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function snapshotsForAgents(array $agentIds): array
    {
        $normalizedAgentIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $agentId): string => trim((string) $agentId),
            $agentIds
        ), static fn (string $agentId): bool => $agentId !== '')));

        $snapshots = [];
        foreach ($normalizedAgentIds as $agentId) {
            $snapshots[$agentId] = [
                'current_job' => null,
                'last_successful_job' => null,
                'last_failed_job' => null,
            ];
        }

        if ($normalizedAgentIds === []) {
            return $snapshots;
        }

        foreach ($this->currentJobs($normalizedAgentIds) as $agentId => $job) {
            $snapshots[$agentId]['current_job'] = $job;
        }
        foreach ($this->lastSuccessfulJobs($normalizedAgentIds) as $agentId => $job) {
            $snapshots[$agentId]['last_successful_job'] = $job;
        }
        foreach ($this->lastFailedJobs($normalizedAgentIds) as $agentId => $job) {
            $snapshots[$agentId]['last_failed_job'] = $job;
        }

        return $snapshots;
    }

    /**
     * @param array<int, string> $agentIds
     * @return array<string, array<string, mixed>>
     */
    private function currentJobs(array $agentIds): array
    {
        $rows = $this->fetchRowsForIds(
            'SELECT claimed_by AS agent_id, id, job_type, asset_uuid, claimed_at, locked_until
             FROM processing_job
             WHERE status = :status
               AND claimed_by IN (%s)
               AND claimed_at IS NOT NULL
               AND locked_until IS NOT NULL',
            'claimed_by',
            $agentIds,
            ['status' => 'claimed']
        );

        $snapshots = [];
        foreach ($rows as $row) {
            $agentId = trim((string) ($row['agent_id'] ?? ''));
            $claimedAt = trim((string) ($row['claimed_at'] ?? ''));
            $lockedUntil = trim((string) ($row['locked_until'] ?? ''));
            if ($agentId === '' || $claimedAt === '' || $lockedUntil === '') {
                continue;
            }

            $candidate = [
                'job_id' => (string) ($row['id'] ?? ''),
                'job_type' => (string) ($row['job_type'] ?? ''),
                'asset_uuid' => (string) ($row['asset_uuid'] ?? ''),
                'claimed_at' => $this->atom($claimedAt),
                'locked_until' => $this->atom($lockedUntil),
            ];

            if (($snapshots[$agentId]['claimed_at'] ?? '') < $candidate['claimed_at']) {
                $snapshots[$agentId] = $candidate;
            }
        }

        return $snapshots;
    }

    /**
     * @param array<int, string> $agentIds
     * @return array<string, array<string, mixed>>
     */
    private function lastSuccessfulJobs(array $agentIds): array
    {
        $rows = $this->fetchRowsForIds(
            'SELECT completed_by AS agent_id, id, job_type, asset_uuid, completed_at
             FROM processing_job
             WHERE status = :status
               AND completed_by IN (%s)
               AND completed_at IS NOT NULL',
            'completed_by',
            $agentIds,
            ['status' => 'completed']
        );

        $snapshots = [];
        foreach ($rows as $row) {
            $agentId = trim((string) ($row['agent_id'] ?? ''));
            $completedAt = trim((string) ($row['completed_at'] ?? ''));
            if ($agentId === '' || $completedAt === '') {
                continue;
            }

            $candidate = [
                'job_id' => (string) ($row['id'] ?? ''),
                'job_type' => (string) ($row['job_type'] ?? ''),
                'asset_uuid' => (string) ($row['asset_uuid'] ?? ''),
                'completed_at' => $this->atom($completedAt),
            ];

            if (($snapshots[$agentId]['completed_at'] ?? '') < $candidate['completed_at']) {
                $snapshots[$agentId] = $candidate;
            }
        }

        return $snapshots;
    }

    /**
     * @param array<int, string> $agentIds
     * @return array<string, array<string, mixed>>
     */
    private function lastFailedJobs(array $agentIds): array
    {
        $rows = $this->fetchRowsForIds(
            'SELECT failed_by AS agent_id, id, job_type, asset_uuid, failed_at, result_payload
             FROM processing_job
             WHERE status = :status
               AND failed_by IN (%s)
               AND failed_at IS NOT NULL',
            'failed_by',
            $agentIds,
            ['status' => 'failed']
        );

        $snapshots = [];
        foreach ($rows as $row) {
            $agentId = trim((string) ($row['agent_id'] ?? ''));
            $failedAt = trim((string) ($row['failed_at'] ?? ''));
            if ($agentId === '' || $failedAt === '') {
                continue;
            }

            $result = $this->decodeArray($row['result_payload'] ?? null);
            $candidate = [
                'job_id' => (string) ($row['id'] ?? ''),
                'job_type' => (string) ($row['job_type'] ?? ''),
                'asset_uuid' => (string) ($row['asset_uuid'] ?? ''),
                'failed_at' => $this->atom($failedAt),
                'error_code' => (string) ($result['error_code'] ?? ''),
            ];

            if ($candidate['error_code'] === '') {
                continue;
            }

            if (($snapshots[$agentId]['failed_at'] ?? '') < $candidate['failed_at']) {
                $snapshots[$agentId] = $candidate;
            }
        }

        return $snapshots;
    }

    /**
     * @param array<int, string> $agentIds
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function fetchRowsForIds(string $sqlTemplate, string $column, array $agentIds, array $params): array
    {
        $placeholders = [];
        foreach ($agentIds as $index => $agentId) {
            $key = sprintf('%s_%d', $column, $index);
            $placeholders[] = ':'.$key;
            $params[$key] = $agentId;
        }

        return $this->connection->fetchAllAssociative(
            sprintf($sqlTemplate, implode(', ', $placeholders)),
            $params
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArray(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function atom(string $value): string
    {
        try {
            return (new \DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Throwable) {
            return '';
        }
    }
}
