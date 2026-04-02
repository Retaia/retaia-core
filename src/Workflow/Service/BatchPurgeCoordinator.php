<?php

namespace App\Workflow\Service;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Entity\Asset;
use App\Lock\OperationLockType;
use App\Lock\Repository\OperationLockRepository;
use Doctrine\DBAL\Connection;

final class BatchPurgeCoordinator
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private AssetStateMachine $stateMachine,
        private Connection $connection,
        private OperationLockRepository $locks,
        private AssetPurgeStorageService $assetPurgeStorage,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function previewPurge(Asset $asset): array
    {
        return [
            'uuid' => $asset->getUuid(),
            'state' => $asset->getState()->value,
            'allowed' => $asset->getState() === AssetState::REJECTED && !$this->locks->hasActiveLock($asset->getUuid()),
        ];
    }

    public function purge(Asset $asset): bool
    {
        if ($asset->getState() !== AssetState::REJECTED) {
            return false;
        }

        if ($this->locks->hasActiveLock($asset->getUuid())) {
            return false;
        }

        $activeClaims = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM processing_job WHERE asset_uuid = :assetUuid AND status = :claimed AND locked_until >= :now',
            [
                'assetUuid' => $asset->getUuid(),
                'claimed' => 'claimed',
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]
        );
        if ($activeClaims > 0) {
            return false;
        }

        if (!$this->locks->acquire($asset->getUuid(), OperationLockType::PURGE, 'workflow:purge')) {
            return false;
        }

        try {
            if (!$this->assetPurgeStorage->deleteAssetAndDerivedFiles($asset)) {
                return false;
            }
            $this->stateMachine->transition($asset, AssetState::PURGED);
            $this->assets->save($asset);
        } finally {
            $this->locks->release($asset->getUuid(), OperationLockType::PURGE);
        }

        return true;
    }
}
