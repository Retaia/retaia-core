<?php

namespace App\Workflow\Service;

use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Asset\Service\StateConflictException;
use App\Entity\Asset;
use App\Lock\Repository\OperationLockRepository;

final class BatchDecisionCoordinator
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private AssetStateMachine $stateMachine,
        private OperationLockRepository $locks,
    ) {
    }

    /**
     * @param array<int, string> $uuids
     * @return array<string, mixed>
     */
    public function previewDecisions(array $uuids, string $action): array
    {
        $eligible = [];
        $ineligible = [];

        foreach ($uuids as $uuid) {
            $asset = $this->assets->findByUuid($uuid);
            if (!$asset instanceof Asset) {
                $ineligible[] = ['uuid' => $uuid, 'code' => 'NOT_FOUND'];
                continue;
            }

            try {
                $cloned = clone $asset;
                if ($this->locks->hasActiveLock($cloned->getUuid())) {
                    throw new StateConflictException('asset locked');
                }
                $this->stateMachine->decide($cloned, $action);
                $eligible[] = ['uuid' => $uuid, 'target_state' => $cloned->getState()->value];
            } catch (StateConflictException) {
                $ineligible[] = ['uuid' => $uuid, 'code' => 'STATE_CONFLICT'];
            }
        }

        return [
            'action' => strtoupper($action),
            'eligible_count' => count($eligible),
            'ineligible_count' => count($ineligible),
            'eligible' => $eligible,
            'ineligible' => $ineligible,
        ];
    }

    /**
     * @param array<int, string> $uuids
     * @return array<string, mixed>
     */
    public function applyDecisions(array $uuids, string $action): array
    {
        $preview = $this->previewDecisions($uuids, $action);
        $applied = [];

        foreach ($preview['eligible'] as $eligible) {
            $uuid = (string) ($eligible['uuid'] ?? '');
            $asset = $this->assets->findByUuid($uuid);
            if (!$asset instanceof Asset) {
                continue;
            }

            $this->stateMachine->decide($asset, $action);
            $this->assets->save($asset);
            $applied[] = ['uuid' => $uuid, 'state' => $asset->getState()->value];
        }

        return [
            'action' => strtoupper($action),
            'applied_count' => count($applied),
            'applied' => $applied,
            'ineligible' => $preview['ineligible'],
        ];
    }
}
