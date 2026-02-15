<?php

namespace App\Infrastructure\Workflow;

use App\Application\Workflow\Port\WorkflowGateway as WorkflowGatewayPort;
use App\Application\Workflow\PurgeAssetResult;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Workflow\Service\BatchWorkflowService;

final class WorkflowGateway implements WorkflowGatewayPort
{
    public function __construct(
        private BatchWorkflowService $workflows,
        private AssetRepositoryInterface $assets,
    ) {
    }

    public function previewMoves(?array $uuids): array
    {
        return $this->workflows->previewMoves($uuids);
    }

    public function applyMoves(?array $uuids): array
    {
        return $this->workflows->applyMoves($uuids);
    }

    public function getBatchReport(string $batchId): ?array
    {
        return $this->workflows->getBatchReport($batchId);
    }

    public function previewDecisions(array $uuids, string $action): array
    {
        return $this->workflows->previewDecisions($uuids, $action);
    }

    public function applyDecisions(array $uuids, string $action): array
    {
        return $this->workflows->applyDecisions($uuids, $action);
    }

    public function previewPurge(string $assetUuid): ?array
    {
        $asset = $this->assets->findByUuid($assetUuid);
        if ($asset === null) {
            return null;
        }

        return $this->workflows->previewPurge($asset);
    }

    public function purge(string $assetUuid): array
    {
        $asset = $this->assets->findByUuid($assetUuid);
        if ($asset === null) {
            return ['status' => PurgeAssetResult::STATUS_NOT_FOUND, 'asset' => null];
        }

        if (!$this->workflows->purge($asset)) {
            return ['status' => PurgeAssetResult::STATUS_STATE_CONFLICT, 'asset' => null];
        }

        return [
            'status' => PurgeAssetResult::STATUS_PURGED,
            'asset' => [
                'uuid' => $asset->getUuid(),
                'state' => $asset->getState()->value,
            ],
        ];
    }
}
