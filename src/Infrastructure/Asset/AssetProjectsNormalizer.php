<?php

namespace App\Infrastructure\Asset;

final class AssetProjectsNormalizer
{
    /**
     * @param mixed $projects
     * @return array<int, array<string, mixed>>|null
     */
    public function normalize(mixed $projects): ?array
    {
        if (!is_array($projects)) {
            return null;
        }

        $normalized = [];
        $seen = [];
        foreach ($projects as $project) {
            if (!is_array($project)) {
                return null;
            }

            $projectId = trim((string) ($project['project_id'] ?? ''));
            $projectName = trim((string) ($project['project_name'] ?? ''));
            $createdAt = trim((string) ($project['created_at'] ?? ''));
            if ($projectId === '' || $projectName === '' || !$this->isValidDateTime($createdAt)) {
                return null;
            }

            if (isset($seen[$projectId])) {
                continue;
            }

            $item = [
                'project_id' => $projectId,
                'project_name' => $projectName,
                'created_at' => $createdAt,
            ];

            if (array_key_exists('description', $project)) {
                $description = $project['description'];
                if ($description !== null && !is_string($description)) {
                    return null;
                }
                $item['description'] = $description;
            }

            $normalized[] = $item;
            $seen[$projectId] = true;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<int, array<string, mixed>>
     */
    public function fromFields(array $fields): array
    {
        $projects = $fields['projects'] ?? null;

        return is_array($projects) ? $projects : [];
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
