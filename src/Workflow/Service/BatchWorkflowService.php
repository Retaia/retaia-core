<?php

namespace App\Workflow\Service;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Asset\Service\StateConflictException;
use App\Entity\Asset;
use App\Derived\DerivedFileRepositoryInterface;
use App\Lock\OperationLockType;
use App\Lock\Repository\OperationLockRepository;
use App\Storage\BusinessStorageRegistryInterface;
use Doctrine\DBAL\Connection;

final class BatchWorkflowService
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private AssetStateMachine $stateMachine,
        private Connection $connection,
        private OperationLockRepository $locks,
        private DerivedFileRepositoryInterface $derivedFiles,
        private ?BusinessStorageRegistryInterface $storageRegistry = null,
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
            } catch (StateConflictException $exception) {
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

        $this->storeBatchReport($batchId, $report);

        return $report;
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
            } catch (StateConflictException $exception) {
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

    /**
     * @return array<string, mixed>|null
     */
    public function getBatchReport(string $batchId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT payload FROM batch_move_report WHERE batch_id = :batchId',
            ['batchId' => $batchId]
        );

        if (!is_array($row) || !is_string($row['payload'] ?? null)) {
            return null;
        }

        $decoded = json_decode((string) $row['payload'], true);

        return is_array($decoded) ? $decoded : null;
    }

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
            if (!$this->deleteAssetAndDerivedFiles($asset)) {
                return false;
            }
            $this->stateMachine->transition($asset, AssetState::PURGED);
            $this->assets->save($asset);
        } finally {
            $this->locks->release($asset->getUuid(), OperationLockType::PURGE);
        }

        return true;
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

    /**
     * @param array<string, mixed> $payload
     */
    private function storeBatchReport(string $batchId, array $payload): void
    {
        $this->connection->insert('batch_move_report', [
            'batch_id' => $batchId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function deleteAssetAndDerivedFiles(Asset $asset): bool
    {
        $storage = $this->storageForAsset($asset);
        if ($storage !== null) {
            $fields = $asset->getFields();
            $paths = [
                (string) ($fields['current_path'] ?? ''),
                (string) ($fields['source_path'] ?? ''),
                is_array($fields['paths'] ?? null) ? (string) (($fields['paths']['original_relative'] ?? '')) : '',
            ];
            $sidecars = is_array($fields['paths']['sidecars_relative'] ?? null) ? $fields['paths']['sidecars_relative'] : [];
            foreach ($sidecars as $sidecar) {
                $paths[] = (string) $sidecar;
            }

            foreach ($paths as $path) {
                $normalized = $this->normalizeRelativePath($path);
                if ($normalized === null) {
                    continue;
                }

                try {
                    $storage->deleteFile($normalized);
                } catch (\Throwable) {
                    return false;
                }
            }
        }

        $paths = $this->derivedFiles->listStoragePathsByAsset($asset->getUuid());

        if ($storage !== null) {
            foreach ($paths as $path) {
                $storagePath = $this->normalizeRelativePath($path);
                if ($storagePath === null) {
                    continue;
                }

                try {
                    $storage->deleteFile($storagePath);
                } catch (\Throwable) {
                    return false;
                }
            }
        }

        $this->derivedFiles->deleteByAsset($asset->getUuid());

        if ($storage !== null) {
            foreach ([
                '.derived/'.$asset->getUuid(),
                'ARCHIVE/.derived/'.$asset->getUuid(),
                'REJECTS/.derived/'.$asset->getUuid(),
                'derived/'.$asset->getUuid(),
                'ARCHIVE/derived/'.$asset->getUuid(),
                'REJECTS/derived/'.$asset->getUuid(),
            ] as $directory) {
                try {
                    $storage->deleteDirectory($directory);
                } catch (\Throwable) {
                    return false;
                }
            }
        }

        return true;
    }

    private function normalizeRelativePath(string $path): ?string
    {
        $normalized = ltrim(trim($path), '/');
        if ($normalized === '' || str_contains($normalized, "\0") || str_contains($normalized, '../') || str_contains($normalized, '..\\')) {
            return null;
        }

        return $normalized;
    }

    private function storageForAsset(Asset $asset): ?\App\Storage\BusinessStorageInterface
    {
        if (!$this->storageRegistry instanceof BusinessStorageRegistryInterface) {
            return null;
        }

        $fields = $asset->getFields();
        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $storageId = trim((string) ($paths['storage_id'] ?? $fields['storage_id'] ?? $this->storageRegistry->defaultStorageId()));

        return $this->storageRegistry->get($storageId === '' ? $this->storageRegistry->defaultStorageId() : $storageId)->storage;
    }
}
