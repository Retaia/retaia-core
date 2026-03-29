<?php

namespace App\Infrastructure\Asset;

use App\Storage\BusinessStorageRegistryInterface;

final class AssetCanonicalPathsProjector
{
    public function __construct(
        private BusinessStorageRegistryInterface $storageRegistry,
    ) {
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function project(array $fields, string $filename): array
    {
        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $storageId = $this->requiredStorageId($paths, $filename);
        $original = $this->requiredOriginalRelativePath($paths, $filename);

        return [
            'storage_id' => $storageId,
            'original_relative' => $original,
            'sidecars_relative' => $this->sanitizeRelativePaths(is_array($paths['sidecars_relative'] ?? null) ? $paths['sidecars_relative'] : []),
        ];
    }

    /**
     * @param array<string, mixed> $paths
     */
    private function requiredStorageId(array $paths, string $filename): string
    {
        $storageId = trim((string) ($paths['storage_id'] ?? ''));
        if ($storageId === '') {
            throw new \RuntimeException(sprintf('Asset "%s" is missing canonical paths.storage_id.', $filename));
        }
        if (!$this->storageRegistry->has($storageId)) {
            throw new \RuntimeException(sprintf('Asset "%s" references unknown storage "%s".', $filename, $storageId));
        }

        return $storageId;
    }

    /**
     * @param array<string, mixed> $paths
     */
    private function requiredOriginalRelativePath(array $paths, string $filename): string
    {
        $original = $this->sanitizeRelativePath((string) ($paths['original_relative'] ?? ''));
        if ($original === '') {
            throw new \RuntimeException(sprintf('Asset "%s" is missing canonical paths.original_relative.', $filename));
        }

        return $original;
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
}
