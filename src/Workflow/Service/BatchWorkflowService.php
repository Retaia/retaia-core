<?php

namespace App\Workflow\Service;

use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Entity\Asset;
use App\Lock\Repository\OperationLockRepository;
use App\Workflow\BatchMoveReportRepositoryInterface;
use Doctrine\DBAL\Connection;

final class BatchWorkflowService
{
    private BatchMoveCoordinator $moveCoordinator;
    private BatchDecisionCoordinator $decisionCoordinator;
    private BatchPurgeCoordinator $purgeCoordinator;

    public function __construct(
        AssetRepositoryInterface $assets,
        AssetStateMachine $stateMachine,
        Connection $connection,
        OperationLockRepository $locks,
        BatchMoveReportRepositoryInterface $batchMoveReports,
        AssetPurgeStorageService $assetPurgeStorage,
        ?BatchMoveCoordinator $moveCoordinator = null,
        ?BatchDecisionCoordinator $decisionCoordinator = null,
        ?BatchPurgeCoordinator $purgeCoordinator = null,
    ) {
        $this->moveCoordinator = $moveCoordinator ?? new BatchMoveCoordinator($assets, $stateMachine, $locks, $batchMoveReports);
        $this->decisionCoordinator = $decisionCoordinator ?? new BatchDecisionCoordinator($assets, $stateMachine, $locks);
        $this->purgeCoordinator = $purgeCoordinator ?? new BatchPurgeCoordinator($assets, $stateMachine, $connection, $locks, $assetPurgeStorage);
    }

    /**
     * @param array<int, string>|null $uuids
     * @return array<string, mixed>
     */
    public function previewMoves(?array $uuids = null): array
    {
        return $this->moveCoordinator->previewMoves($uuids);
    }

    /**
     * @param array<int, string>|null $uuids
     * @return array<string, mixed>
     */
    public function applyMoves(?array $uuids = null): array
    {
        return $this->moveCoordinator->applyMoves($uuids);
    }

    /**
     * @param array<int, string> $uuids
     * @return array<string, mixed>
     */
    public function previewDecisions(array $uuids, string $action): array
    {
        return $this->decisionCoordinator->previewDecisions($uuids, $action);
    }

    /**
     * @param array<int, string> $uuids
     * @return array<string, mixed>
     */
    public function applyDecisions(array $uuids, string $action): array
    {
        return $this->decisionCoordinator->applyDecisions($uuids, $action);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBatchReport(string $batchId): ?array
    {
        return $this->moveCoordinator->getBatchReport($batchId);
    }

    /**
     * @return array<string, mixed>
     */
    public function previewPurge(Asset $asset): array
    {
        return $this->purgeCoordinator->previewPurge($asset);
    }

    public function purge(Asset $asset): bool
    {
        return $this->purgeCoordinator->purge($asset);
    }
}
