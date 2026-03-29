<?php

namespace App\Infrastructure\Asset;

final class AssetFieldViewProjector
{
    private const HIDDEN_FIELD_KEYS = ['projects'];

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function publicFields(array $fields): array
    {
        foreach (self::HIDDEN_FIELD_KEYS as $key) {
            unset($fields[$key]);
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<int, array<string, mixed>>
     */
    public function projects(array $fields): array
    {
        $projects = $fields['projects'] ?? null;
        if (!is_array($projects)) {
            return [];
        }

        $normalized = [];
        $seen = [];
        foreach ($projects as $project) {
            if (!is_array($project)) {
                continue;
            }

            $projectId = trim((string) ($project['project_id'] ?? ''));
            $projectName = trim((string) ($project['project_name'] ?? ''));
            $createdAt = trim((string) ($project['created_at'] ?? ''));
            if ($projectId === '' || $projectName === '' || !$this->isValidDateTime($createdAt) || isset($seen[$projectId])) {
                continue;
            }

            $item = [
                'project_id' => $projectId,
                'project_name' => $projectName,
                'created_at' => $createdAt,
            ];

            if (array_key_exists('description', $project)) {
                $description = $project['description'];
                $item['description'] = is_string($description) ? $description : null;
            }

            $normalized[] = $item;
            $seen[$projectId] = true;
        }

        return $normalized;
    }

    /**
     * @param mixed $history
     * @return array<int, string>
     */
    public function pathHistory(mixed $history): array
    {
        if (!is_array($history)) {
            return [];
        }

        $items = [];
        foreach ($history as $entry) {
            if (is_array($entry) && is_string($entry['to'] ?? null)) {
                $items[] = (string) $entry['to'];
                continue;
            }
            if (is_string($entry)) {
                $items[] = $entry;
            }
        }

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    public function transcriptStatus(mixed $value): string
    {
        $status = strtoupper(trim((string) $value));
        if (!in_array($status, ['NONE', 'RUNNING', 'DONE', 'FAILED'], true)) {
            return 'NONE';
        }

        return $status;
    }

    private function isValidDateTime(string $value): bool
    {
        try {
            new \DateTimeImmutable($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
