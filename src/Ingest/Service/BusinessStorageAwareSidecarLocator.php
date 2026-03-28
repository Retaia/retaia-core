<?php

namespace App\Ingest\Service;

use App\Storage\BusinessStorageRegistryInterface;

final class BusinessStorageAwareSidecarLocator
{
    public function __construct(
        private BusinessStorageRegistryInterface $storageRegistry,
        private SidecarFileDetector $sidecarFileDetector,
    ) {
    }

    /**
     * @return array<string, string>|null
     */
    public function detectProxyFile(string $storageId, string $sourcePath): ?array
    {
        return $this->sidecarFileDetector->detectProxyFile($sourcePath, $this->fileExists($storageId));
    }

    /**
     * @return array<string, string>|null
     */
    public function detectAuxiliarySidecarFile(string $storageId, string $sourcePath): ?array
    {
        return $this->sidecarFileDetector->detectAuxiliarySidecarFile($sourcePath, $this->fileExists($storageId));
    }

    /**
     * @return array<string, string>|null
     */
    public function detectExistingProxyForOriginal(string $storageId, string $sourcePath): ?array
    {
        return $this->sidecarFileDetector->detectExistingProxyForOriginal($sourcePath, $this->fileExists($storageId));
    }

    /**
     * @return list<string>
     */
    public function existingAuxiliarySidecarsForOriginal(string $storageId, string $sourcePath): array
    {
        $sidecars = $this->sidecarFileDetector->detectExistingAuxiliarySidecarsForOriginal($sourcePath, $this->fileExists($storageId));

        return array_values(array_filter($sidecars, [$this, 'isSafeRelativePath']));
    }

    public function isProxyCandidatePath(string $sourcePath): bool
    {
        return $this->sidecarFileDetector->isProxyCandidatePath($sourcePath);
    }

    public function isAuxiliarySidecarPath(string $sourcePath): bool
    {
        return $this->sidecarFileDetector->isAuxiliarySidecarPath($sourcePath);
    }

    public function auxiliaryUnmatchedReason(string $storageId, string $sourcePath): ?string
    {
        return $this->sidecarFileDetector->auxiliaryUnmatchedReason($sourcePath, $this->fileExists($storageId));
    }

    private function fileExists(string $storageId): callable
    {
        $storage = $this->storageRegistry->get($storageId)->storage;

        return $storage->fileExists(...);
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        return !str_starts_with($path, '/') && !str_contains($path, '..\\') && !str_contains($path, '../');
    }
}
