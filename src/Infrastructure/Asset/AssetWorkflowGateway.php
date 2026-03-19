<?php

namespace App\Infrastructure\Asset;

use App\Application\Asset\DecideAssetResult;
use App\Application\Asset\Port\AssetWorkflowGateway as AssetWorkflowGatewayPort;
use App\Application\Asset\ReopenAssetResult;
use App\Application\Asset\ReprocessAssetResult;
use App\Asset\AssetRevisionTag;
use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Asset\Service\StateConflictException;
use App\Entity\Asset;
use App\Lock\Repository\OperationLockRepository;

final class AssetWorkflowGateway implements AssetWorkflowGatewayPort
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private AssetStateMachine $stateMachine,
        private OperationLockRepository $locks,
    ) {
    }

    public function decide(string $uuid, string $action): array
    {
        $asset = $this->assets->findByUuid($uuid);
        if (!$asset instanceof Asset) {
            return ['status' => DecideAssetResult::STATUS_NOT_FOUND, 'payload' => null];
        }

        if ($this->locks->hasActiveLock($asset->getUuid())) {
            return ['status' => DecideAssetResult::STATUS_STATE_CONFLICT, 'payload' => null];
        }

        if (trim($action) === '') {
            return ['status' => DecideAssetResult::STATUS_VALIDATION_FAILED_ACTION_REQUIRED, 'payload' => null];
        }

        try {
            $this->stateMachine->decide($asset, $action);
            $this->assets->save($asset);
        } catch (StateConflictException) {
            return ['status' => DecideAssetResult::STATUS_STATE_CONFLICT, 'payload' => null];
        }

        return [
            'status' => DecideAssetResult::STATUS_DECIDED,
            'payload' => [
                'uuid' => $asset->getUuid(),
                'state' => $asset->getState()->value,
                'updated_at' => $asset->getUpdatedAt()->format(DATE_ATOM),
                'revision_etag' => AssetRevisionTag::fromAsset($asset),
            ],
        ];
    }

    public function reopen(string $uuid): array
    {
        $asset = $this->assets->findByUuid($uuid);
        if (!$asset instanceof Asset) {
            return ['status' => ReopenAssetResult::STATUS_NOT_FOUND, 'payload' => null];
        }

        if ($this->locks->hasActiveLock($asset->getUuid())) {
            return ['status' => ReopenAssetResult::STATUS_STATE_CONFLICT, 'payload' => null];
        }

        try {
            $this->stateMachine->transition($asset, AssetState::DECISION_PENDING);
            $this->assets->save($asset);
        } catch (StateConflictException) {
            return ['status' => ReopenAssetResult::STATUS_STATE_CONFLICT, 'payload' => null];
        }

        return [
            'status' => ReopenAssetResult::STATUS_REOPENED,
            'payload' => [
                'uuid' => $asset->getUuid(),
                'state' => $asset->getState()->value,
                'updated_at' => $asset->getUpdatedAt()->format(DATE_ATOM),
                'revision_etag' => AssetRevisionTag::fromAsset($asset),
            ],
        ];
    }

    public function reprocess(string $uuid): array
    {
        $asset = $this->assets->findByUuid($uuid);
        if (!$asset instanceof Asset) {
            return ['status' => ReprocessAssetResult::STATUS_NOT_FOUND, 'payload' => null];
        }

        if ($this->locks->hasActiveLock($asset->getUuid())) {
            return ['status' => ReprocessAssetResult::STATUS_STATE_CONFLICT, 'payload' => null];
        }

        try {
            $this->stateMachine->transition($asset, AssetState::READY);
            $this->prepareFieldsForReprocess($asset);
            $this->assets->save($asset);
        } catch (StateConflictException) {
            return ['status' => ReprocessAssetResult::STATUS_STATE_CONFLICT, 'payload' => null];
        }

        return [
            'status' => ReprocessAssetResult::STATUS_REPROCESSED,
            'payload' => [
                'uuid' => $asset->getUuid(),
                'state' => $asset->getState()->value,
                'updated_at' => $asset->getUpdatedAt()->format(DATE_ATOM),
                'revision_etag' => AssetRevisionTag::fromAsset($asset),
            ],
        ];
    }

    private function prepareFieldsForReprocess(Asset $asset): void
    {
        $fields = $asset->getFields();
        $fields['facts_done'] = false;
        $fields['proxy_done'] = false;
        $fields['thumbs_done'] = false;

        $currentVersion = trim((string) ($fields['review_processing_version'] ?? ''));
        if ($currentVersion === '' || !ctype_digit($currentVersion)) {
            $fields['review_processing_version'] = '1';
        } else {
            $fields['review_processing_version'] = (string) (((int) $currentVersion) + 1);
        }

        $asset->setFields($fields);
    }
}
