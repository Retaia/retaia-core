<?php

namespace App\Api\Service;

final class AgentJobProjectionRowMapper
{
    public function agentId(array $row): string
    {
        return trim((string) ($row['agent_id'] ?? ''));
    }

    public function atom(mixed $value): string
    {
        try {
            return is_string($value) && $value !== '' ? (new \DateTimeImmutable($value))->format(DATE_ATOM) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeArray(mixed $value): array
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

    /**
     * @return array<string, string>
     */
    public function currentJobCandidate(array $row): array
    {
        return [
            'job_id' => (string) ($row['id'] ?? ''),
            'job_type' => (string) ($row['job_type'] ?? ''),
            'asset_uuid' => (string) ($row['asset_uuid'] ?? ''),
            'claimed_at' => $this->atom($row['claimed_at'] ?? null),
            'locked_until' => $this->atom($row['locked_until'] ?? null),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function successfulJobCandidate(array $row): array
    {
        return [
            'job_id' => (string) ($row['id'] ?? ''),
            'job_type' => (string) ($row['job_type'] ?? ''),
            'asset_uuid' => (string) ($row['asset_uuid'] ?? ''),
            'completed_at' => $this->atom($row['completed_at'] ?? null),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function failedJobCandidate(array $row): array
    {
        $result = $this->decodeArray($row['result_payload'] ?? null);

        return [
            'job_id' => (string) ($row['id'] ?? ''),
            'job_type' => (string) ($row['job_type'] ?? ''),
            'asset_uuid' => (string) ($row['asset_uuid'] ?? ''),
            'failed_at' => $this->atom($row['failed_at'] ?? null),
            'error_code' => (string) ($result['error_code'] ?? ''),
        ];
    }
}
