<?php

namespace App\Application\Workflow\Port;

interface WorkflowGateway
{
    /**
     * @param array<int, string>|null $uuids
     *
     * @return array<string, mixed>
     */
    public function previewMoves(?array $uuids): array;

    /**
     * @param array<int, string>|null $uuids
     *
     * @return array<string, mixed>
     */
    public function applyMoves(?array $uuids): array;

    /**
     * @return array<string, mixed>|null
     */
    public function getBatchReport(string $batchId): ?array;

    /**
     * @param array<int, string> $uuids
     *
     * @return array<string, mixed>
     */
    public function previewDecisions(array $uuids, string $action): array;

    /**
     * @param array<int, string> $uuids
     *
     * @return array<string, mixed>
     */
    public function applyDecisions(array $uuids, string $action): array;

    /**
     * @return array<string, mixed>|null
     */
    public function previewPurge(string $assetUuid): ?array;

    /**
     * @return array{status: string, asset: array{uuid: string, state: string}|null}
     */
    public function purge(string $assetUuid): array;
}
