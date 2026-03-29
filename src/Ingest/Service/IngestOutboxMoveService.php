<?php

namespace App\Ingest\Service;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Storage\BusinessStorageInterface;
use App\Storage\BusinessStorageRegistryInterface;

final class IngestOutboxMoveService
{
    public function __construct(
        private BusinessStorageRegistryInterface $storageRegistry,
        private AssetRepositoryInterface $assets,
        private IngestAssetPathUpdater $pathUpdater,
        private IngestDerivedOutboxMover $derivedMover,
    ) {
    }

    /**
     * @return array{processed:int,failed:int}
     */
    public function apply(int $limit): array
    {
        $processed = 0;
        $failed = 0;

        $assets = array_merge(
            $this->assets->listAssets(AssetState::ARCHIVED->value, null, null, $limit),
            $this->assets->listAssets(AssetState::REJECTED->value, null, null, $limit),
        );

        foreach ($assets as $asset) {
            try {
                $processed += $this->processAsset($asset);
            } catch (\Throwable) {
                ++$failed;
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    public function processAsset(Asset $asset): int
    {
        $fields = $asset->getFields();
        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $sourcePath = $this->normalizeRelativePath((string) ($paths['original_relative'] ?? ''));
        if ($sourcePath === null) {
            return 0;
        }

        $targetFolder = $asset->getState() === AssetState::ARCHIVED ? 'ARCHIVE' : 'REJECTS';
        $targetRelative = $targetFolder.'/'.basename($sourcePath);
        $storage = $this->storageForAsset($asset);

        if ($storage->fileExists($sourcePath)) {
            [$finalRelative] = $this->resolveAvailableTarget($storage, $targetFolder, $targetRelative, $asset->getUuid());
            $storage->move($sourcePath, $finalRelative);
            $this->derivedMover->moveForAsset($storage, $asset->getUuid(), $targetFolder);
            $this->pathUpdater->persistPathUpdate($asset, $sourcePath, $finalRelative);

            return 1;
        }

        if ($storage->fileExists($targetRelative)) {
            $this->derivedMover->moveForAsset($storage, $asset->getUuid(), $targetFolder);
            $this->pathUpdater->persistPathUpdate($asset, $sourcePath, $targetRelative);
        }

        return 0;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveAvailableTarget(BusinessStorageInterface $storage, string $targetFolder, string $defaultRelative, string $assetUuid): array
    {
        if (!$storage->fileExists($defaultRelative)) {
            return [$defaultRelative, $defaultRelative];
        }

        $ext = pathinfo($defaultRelative, PATHINFO_EXTENSION);
        $name = pathinfo($defaultRelative, PATHINFO_FILENAME);
        $suffix = substr(str_replace('-', '', $assetUuid), 0, 6);
        $attempt = 0;

        while (true) {
            $candidateName = $attempt === 0
                ? ($ext === '' ? sprintf('%s__%s', $name, $suffix) : sprintf('%s__%s.%s', $name, $suffix, $ext))
                : ($ext === '' ? sprintf('%s__%s_%d', $name, $suffix, $attempt) : sprintf('%s__%s_%d.%s', $name, $suffix, $attempt, $ext));

            $relative = $targetFolder.'/'.$candidateName;
            if (!$storage->fileExists($relative)) {
                return [$relative, $relative];
            }

            ++$attempt;
        }
    }

    private function storageForAsset(Asset $asset): BusinessStorageInterface
    {
        $fields = $asset->getFields();
        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $storageId = trim((string) ($paths['storage_id'] ?? ''));
        if ($storageId === '') {
            throw new \RuntimeException(sprintf('Asset %s is missing canonical paths.storage_id.', $asset->getUuid()));
        }

        return $this->storageRegistry->get($storageId)->storage;
    }

    private function normalizeRelativePath(string $path): ?string
    {
        $normalized = ltrim(trim($path), '/');
        if ($normalized === '' || str_contains($normalized, "\0") || str_contains($normalized, '..\\') || str_contains($normalized, '../')) {
            return null;
        }

        return $normalized;
    }
}
