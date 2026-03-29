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
        private AssetPatchPayloadValidator $validator,
        private AssetProjectsNormalizer $projects,
        private AssetPatchStateApplier $stateApplier,
        private AssetPatchViewBuilder $viewBuilder,
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
            if (!$this->validator->isValidTagsPayload($payload['tags'])) {
                return ['status' => PatchAssetResult::STATUS_VALIDATION_FAILED, 'payload' => null];
            }

            $asset->setTags($payload['tags']);
        }

        if (array_key_exists('notes', $payload)) {
            if (!$this->validator->isValidNotesPayload($payload['notes'])) {
                return ['status' => PatchAssetResult::STATUS_VALIDATION_FAILED, 'payload' => null];
            }

            $asset->setNotes($payload['notes']);
        }

        $fields = $asset->getFields();
        if (array_key_exists('fields', $payload)) {
            if (!$this->validator->isValidFieldsPayload($payload['fields'])) {
                return ['status' => PatchAssetResult::STATUS_VALIDATION_FAILED, 'payload' => null];
            }

            $incomingFields = $payload['fields'];
            unset($incomingFields['projects']);
            $fields = array_replace_recursive($fields, $incomingFields);
        }

        if (array_key_exists('projects', $payload)) {
            $projects = $this->projects->normalize($payload['projects']);
            if ($projects === null) {
                return ['status' => PatchAssetResult::STATUS_VALIDATION_FAILED, 'payload' => null];
            }

            if ($projects === []) {
                unset($fields['projects']);
            } else {
                $fields['projects'] = $projects;
            }
        }

        $stateResult = $this->stateApplier->apply($asset, $payload);
        if ($stateResult !== PatchAssetResult::STATUS_PATCHED) {
            return ['status' => $stateResult, 'payload' => null];
        }

        if (!$this->validator->applyMutableMetadataFields($fields, $payload)) {
            return ['status' => PatchAssetResult::STATUS_VALIDATION_FAILED, 'payload' => null];
        }

        $asset->setFields($fields);

        $this->assets->save($asset);

        return [
            'status' => PatchAssetResult::STATUS_PATCHED,
            'payload' => $this->viewBuilder->build($asset),
        ];
    }
}
