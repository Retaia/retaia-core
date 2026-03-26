<?php

namespace App\Infrastructure\Asset;

use App\Application\Asset\PatchAssetResult;
use App\Application\Asset\Port\AssetPatchGateway as AssetPatchGatewayPort;
use App\Asset\AssetState;
use App\Asset\AssetRevisionTag;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Asset\Service\StateConflictException;
use App\Entity\Asset;
use App\Lock\Repository\OperationLockRepository;

final class AssetPatchGateway implements AssetPatchGatewayPort
{
    private const HIDDEN_FIELD_KEYS = ['projects'];
    private const PROCESSING_PROFILES = ['video_standard', 'audio_undefined', 'audio_music', 'audio_voice', 'photo_standard'];

    public function __construct(
        private AssetRepositoryInterface $assets,
        private OperationLockRepository $locks,
        private AssetStateMachine $stateMachine,
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

        if (array_key_exists('tags', $payload)) {
            if (!$this->isValidTagsPayload($payload['tags'])) {
                return ['status' => PatchAssetResult::STATUS_VALIDATION_FAILED, 'payload' => null];
            }

            $asset->setTags($payload['tags']);
        }

        if (array_key_exists('notes', $payload)) {
            if ($payload['notes'] !== null && !is_string($payload['notes'])) {
                return ['status' => PatchAssetResult::STATUS_VALIDATION_FAILED, 'payload' => null];
            }

            $asset->setNotes($payload['notes']);
        }

        $fields = $asset->getFields();
        if (array_key_exists('fields', $payload)) {
            if (!is_array($payload['fields'])) {
                return ['status' => PatchAssetResult::STATUS_VALIDATION_FAILED, 'payload' => null];
            }

            $incomingFields = $payload['fields'];
            unset($incomingFields['projects']);
            $fields = array_replace_recursive($fields, $incomingFields);
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

        $stateResult = $this->applyMutableState($asset, $payload);
        if ($stateResult !== PatchAssetResult::STATUS_PATCHED) {
            return ['status' => $stateResult, 'payload' => null];
        }

        if (!$this->applyMutableMetadataFields($fields, $payload)) {
            return ['status' => PatchAssetResult::STATUS_VALIDATION_FAILED, 'payload' => null];
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
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $payload
     */
    private function applyMutableMetadataFields(array &$fields, array $payload): bool
    {
        $dateTimeFields = ['captured_at'];
        foreach ($dateTimeFields as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $value = $payload[$field];
            if ($value !== null && (!$this->isNonEmptyString($value) || !$this->isValidDateTime($value))) {
                return false;
            }
            $fields[$field] = $value;
        }

        foreach ([
            'gps_latitude' => [-90, 90],
            'gps_longitude' => [-180, 180],
            'gps_altitude_m' => null,
            'gps_altitude_relative_m' => null,
            'gps_altitude_absolute_m' => null,
        ] as $field => $range) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $value = $payload[$field];
            if ($value !== null && !is_int($value) && !is_float($value)) {
                return false;
            }
            if (is_array($range) && $value !== null && ($value < $range[0] || $value > $range[1])) {
                return false;
            }
            $fields[$field] = $value;
        }

        foreach (['location_country', 'location_city', 'location_label'] as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $value = $payload[$field];
            if ($value !== null && !is_string($value)) {
                return false;
            }
            $fields[$field] = $value;
        }

        if (array_key_exists('processing_profile', $payload)) {
            $value = $payload['processing_profile'];
            if ($value !== null && (!is_string($value) || !in_array($value, self::PROCESSING_PROFILES, true))) {
                return false;
            }
            $fields['processing_profile'] = $value;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyMutableState(Asset $asset, array $payload): string
    {
        if (!array_key_exists('state', $payload)) {
            return PatchAssetResult::STATUS_PATCHED;
        }

        $value = $payload['state'];
        if (!is_string($value)) {
            return PatchAssetResult::STATUS_VALIDATION_FAILED;
        }

        $normalized = strtoupper(trim($value));
        if (!in_array($normalized, [
            AssetState::DECISION_PENDING->value,
            AssetState::DECIDED_KEEP->value,
            AssetState::DECIDED_REJECT->value,
            AssetState::ARCHIVED->value,
            AssetState::REJECTED->value,
        ], true)) {
            return PatchAssetResult::STATUS_VALIDATION_FAILED;
        }

        $target = AssetState::tryFrom($normalized);
        if (!$target instanceof AssetState) {
            return PatchAssetResult::STATUS_VALIDATION_FAILED;
        }

        try {
            $this->stateMachine->transition($asset, $target);
        } catch (StateConflictException) {
            return PatchAssetResult::STATUS_STATE_CONFLICT;
        }

        return PatchAssetResult::STATUS_PATCHED;
    }

    private function isValidTagsPayload(mixed $tags): bool
    {
        if (!is_array($tags)) {
            return false;
        }

        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                return false;
            }
        }

        return true;
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
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
