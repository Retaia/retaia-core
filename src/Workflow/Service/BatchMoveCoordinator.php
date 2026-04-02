<?php

namespace App\Workflow\Service;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Asset\Service\StateConflictException;
use App\Entity\Asset;
use App\Lock\OperationLockType;
use App\Lock\Repository\OperationLockRepository;
use App\Workflow\BatchMoveReportRepositoryInterface;

final class BatchMoveCoordinator
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private AssetStateMachine $stateMachine,
        private OperationLockRepository $locks,
        private BatchMoveReportRepositoryInterface $batchMoveReports,
    ) {
    }

    /**
     * @param array<int, string>|null $uuids
     * @return array<string, mixed>
     */
    public function previewMoves(?array $uuids = null): array
    {
        $items = $this->eligibleMoveItems($uuids);
        $names = [];

        $preview = [];
        foreach ($items as $asset) {
            $targetState = $asset->getState() === AssetState::DECIDED_KEEP ? AssetState::ARCHIVED : AssetState::REJECTED;
            $targetName = $asset->getFilename();

            if (isset($names[$targetName])) {
                $targetName = sprintf('%s__%s', $targetName, substr(str_replace('-', '', $asset->getUuid()), 0, 6));
            }
            $names[$targetName] = true;

            $preview[] = [
                'uuid' => $asset->getUuid(),
                'current_state' => $asset->getState()->value,
                'target_state' => $targetState->value,
                'target_filename' => $targetName,
            ];
        }

        return [
            'eligible_count' => count($preview),
            'items' => $preview,
        ];
    }

    /**
     * @param array<int, string>|null $uuids
     * @return array<string, mixed>
     */
    public function applyMoves(?array $uuids = null): array
    {
        $items = $this->eligibleMoveItems($uuids);
        $batchId = bin2hex(random_bytes(8));

        $successes = [];
        $errors = [];

        foreach ($items as $asset) {
            $targetState = $asset->getState() === AssetState::DECIDED_KEEP ? AssetState::ARCHIVED : AssetState::REJECTED;
            if ($this->locks->hasActiveLock($asset->getUuid())) {
                $errors[] = [
                    'uuid' => $asset->getUuid(),
                    'code' => 'STATE_CONFLICT',
                ];
                continue;
            }

            $acquired = $this->locks->acquire($asset->getUuid(), OperationLockType::MOVE, 'workflow:move');
            if (!$acquired) {
                $errors[] = [
                    'uuid' => $asset->getUuid(),
                    'code' => 'STATE_CONFLICT',
                ];
                continue;
            }

            try {
                $this->stateMachine->transition($asset, AssetState::MOVE_QUEUED);
                $this->stateMachine->transition($asset, $targetState);
                $this->assets->save($asset);
                $successes[] = [
                    'uuid' => $asset->getUuid(),
                    'final_state' => $asset->getState()->value,
                ];
            } catch (StateConflictException) {
                $errors[] = [
                    'uuid' => $asset->getUuid(),
                    'code' => 'STATE_CONFLICT',
                ];
            } finally {
                $this->locks->release($asset->getUuid(), OperationLockType::MOVE);
            }
        }

        $report = [
            'batch_id' => $batchId,
            'success_count' => count($successes),
            'error_count' => count($errors),
            'successes' => $successes,
            'errors' => $errors,
        ];

        $this->batchMoveReports->store($batchId, $report);

        return $report;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBatchReport(string $batchId): ?array
    {
        return $this->batchMoveReports->find($batchId);
    }

    /**
     * @param array<int, string>|null $uuids
     * @return array<int, Asset>
     */
    private function eligibleMoveItems(?array $uuids = null): array
    {
        $assetList = $this->assets->listAssets(null, null, null, 500);

        return array_values(array_filter($assetList, static function (Asset $asset) use ($uuids): bool {
            if ($uuids !== null && $uuids !== [] && !in_array($asset->getUuid(), $uuids, true)) {
                return false;
            }

            return in_array($asset->getState(), [AssetState::DECIDED_KEEP, AssetState::DECIDED_REJECT], true);
        }));
    }
}
