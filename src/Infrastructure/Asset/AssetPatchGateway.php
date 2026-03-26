<?php

namespace App\Infrastructure\Asset;

use App\Application\Asset\PatchAssetResult;
use App\Application\Asset\Port\AssetPatchGateway as AssetPatchGatewayPort;
use App\Asset\AssetState;
use App\Asset\AssetRevisionTag;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Lock\Repository\OperationLockRepository;

final class AssetPatchGateway implements AssetPatchGatewayPort
{
    private const HIDDEN_FIELD_KEYS = ['projects'];

    public function __construct(
        private AssetRepositoryInterface $assets,
        private OperationLockRepository $locks,
    ) {
    }

    public function patch(string $uuid, array $payload): array
    {
        $asset = $this->assets->findByUuid($uuid);
        if (!$asset instanceof Asset) {
            return ['status' => PatchAssetResult::STATUS_NOT_FOUND, 'payload' => null];
        }

        if ($asset->getState() === AssetState::PURGED) {
            return ['status' => PatchAssetResult::STATUS_PURGED_READ_ONLY, 'payload' => null];
        }

        if ($this->locks->hasActiveLock($asset->getUuid())) {
            return ['status' => PatchAssetResult::STATUS_STATE_CONFLICT, 'payload' => null];
        }

        if (array_key_exists('tags', $payload) && is_array($payload['tags'])) {
            $asset->setTags($payload['tags']);
        }

        if (array_key_exists('notes', $payload)) {
            $asset->setNotes(is_string($payload['notes']) ? $payload['notes'] : null);
        }

        $fields = $asset->getFields();
        $existingProjects = is_array($fields['projects'] ?? null) ? $fields['projects'] : null;
        if (array_key_exists('fields', $payload) && is_array($payload['fields'])) {
            $fields = $payload['fields'];
            unset($fields['projects']);
            if (!array_key_exists('projects', $payload) && is_array($existingProjects)) {
                $fields['projects'] = $existingProjects;
            }
        }

        if (array_key_exists('projects', $payload)) {
            $projects = $this->normalizeProjects($payload['projects']);
            if ($projects === null) {
                return ['status' => PatchAssetResult::STATUS_VALIDATION_FAILED, 'payload' => null];
            }

            if ($projects === []) {
                unset($fields['projects']);
            } else {
                $fields['projects'] = $projects;
            }
        }

        $asset->setFields($fields);

        $this->assets->save($asset);

        return [
            'status' => PatchAssetResult::STATUS_PATCHED,
            'payload' => [
                'uuid' => $asset->getUuid(),
                'media_type' => $asset->getMediaType(),
                'filename' => $asset->getFilename(),
                'state' => $asset->getState()->value,
                'tags' => $asset->getTags(),
                'notes' => $asset->getNotes(),
                'fields' => $this->publicFields($asset->getFields()),
                'projects' => $this->projectsFromFields($asset->getFields()),
                'created_at' => $asset->getCreatedAt()->format(DATE_ATOM),
                'updated_at' => $asset->getUpdatedAt()->format(DATE_ATOM),
                'revision_etag' => AssetRevisionTag::fromAsset($asset),
            ],
        ];
    }

    /**
     * @param mixed $projects
     * @return array<int, array<string, mixed>>|null
     */
    private function normalizeProjects(mixed $projects): ?array
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
    private function projectsFromFields(array $fields): array
    {
        $projects = $fields['projects'] ?? null;

        return is_array($projects) ? $projects : [];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function publicFields(array $fields): array
    {
        foreach (self::HIDDEN_FIELD_KEYS as $key) {
            unset($fields[$key]);
        }

        return $fields;
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
