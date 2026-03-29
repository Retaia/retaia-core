<?php

namespace App\Job\Repository;

use App\Storage\BusinessStorageRegistryInterface;

final class JobSourceProjector
{
    public function __construct(
        private BusinessStorageRegistryInterface $storageRegistry,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function sourceFromAssetFields(mixed $assetFieldsRaw, string $assetFilename): array
    {
        $fields = [];
        if (is_array($assetFieldsRaw)) {
            $fields = $assetFieldsRaw;
        } elseif (is_string($assetFieldsRaw) && $assetFieldsRaw !== '') {
            $decoded = json_decode($assetFieldsRaw, true);
            if (is_array($decoded)) {
                $fields = $decoded;
            }
        }

        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $storageId = $this->requiredStorageId($paths, $assetFilename);
        $original = $this->requiredOriginalRelativePath($paths, $assetFilename);
        $sidecars = $this->sanitizeRelativePaths(is_array($paths['sidecars_relative'] ?? null) ? $paths['sidecars_relative'] : []);

        return [
            'storage_id' => $storageId,
            'original_relative' => $original,
            'sidecars_relative' => $sidecars,
        ];
    }

    private function sanitizeRelativePath(string $path): string
    {
        $trimmed = ltrim(trim($path), '/');
        if ($trimmed === '' || str_contains($trimmed, "\0") || str_contains($trimmed, '../') || str_contains($trimmed, '..\\')) {
            return '';
        }

        return $trimmed;
    }

    /**
     * @param mixed $paths
     * @return array<int, string>
     */
    private function sanitizeRelativePaths(mixed $paths): array
    {
        if (!is_array($paths)) {
            return [];
        }

        $result = [];
        foreach ($paths as $path) {
            $normalized = $this->sanitizeRelativePath((string) $path);
            if ($normalized !== '') {
                $result[] = $normalized;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array<string, mixed> $paths
     */
    private function requiredStorageId(array $paths, string $assetFilename): string
    {
        $storageId = trim((string) ($paths['storage_id'] ?? ''));
        if ($storageId === '') {
            throw new \RuntimeException(sprintf('Asset "%s" is missing canonical paths.storage_id.', $assetFilename));
        }
        if (!$this->storageRegistry->has($storageId)) {
            throw new \RuntimeException(sprintf('Asset "%s" references unknown storage "%s".', $assetFilename, $storageId));
        }

        return $storageId;
    }

    /**
     * @param array<string, mixed> $paths
     */
    private function requiredOriginalRelativePath(array $paths, string $assetFilename): string
    {
        $original = $this->sanitizeRelativePath((string) ($paths['original_relative'] ?? ''));
        if ($original === '') {
            throw new \RuntimeException(sprintf('Asset "%s" is missing canonical paths.original_relative.', $assetFilename));
        }

        return $original;
    }
}
