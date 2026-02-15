<?php

namespace App\Infrastructure\Asset;

use App\Application\Asset\PatchAssetResult;
use App\Application\Asset\Port\AssetPatchGateway as AssetPatchGatewayPort;
use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Lock\Repository\OperationLockRepository;

final class AssetPatchGateway implements AssetPatchGatewayPort
{
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

        if (array_key_exists('fields', $payload) && is_array($payload['fields'])) {
            $asset->setFields($payload['fields']);
        }

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
                'fields' => $asset->getFields(),
                'created_at' => $asset->getCreatedAt()->format(DATE_ATOM),
                'updated_at' => $asset->getUpdatedAt()->format(DATE_ATOM),
            ],
        ];
    }
}
