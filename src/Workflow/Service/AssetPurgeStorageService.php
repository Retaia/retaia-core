<?php

namespace App\Workflow\Service;

use App\Derived\DerivedFileRepositoryInterface;
use App\Entity\Asset;
use App\Storage\BusinessStorageInterface;
use App\Storage\BusinessStorageRegistryInterface;

final class AssetPurgeStorageService
{
    public function __construct(
        private DerivedFileRepositoryInterface $derivedFiles,
        private ?BusinessStorageRegistryInterface $storageRegistry = null,
    ) {
    }

    public function deleteAssetAndDerivedFiles(Asset $asset): bool
    {
        $storage = $this->storageForAsset($asset);
        if ($storage !== null) {
            $fields = $asset->getFields();
            $paths = [
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

    private function storageForAsset(Asset $asset): ?BusinessStorageInterface
    {
        if (!$this->storageRegistry instanceof BusinessStorageRegistryInterface) {
            return null;
        }

        $fields = $asset->getFields();
        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $storageId = trim((string) ($paths['storage_id'] ?? ''));
        if ($storageId === '') {
            throw new \RuntimeException(sprintf('Asset %s is missing canonical paths.storage_id.', $asset->getUuid()));
        }

        return $this->storageRegistry->get($storageId)->storage;
    }
}
