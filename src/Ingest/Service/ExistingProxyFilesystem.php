<?php

namespace App\Ingest\Service;

use App\Storage\BusinessStorageInterface;

final class ExistingProxyFilesystem implements ExistingProxyFilesystemInterface
{
    public function isFile(BusinessStorageInterface $storage, string $relativePath): bool
    {
        return $storage->fileExists($relativePath);
    }

    public function fileSize(BusinessStorageInterface $storage, string $relativePath): int
    {
        return $storage->fileSize($relativePath);
    }

    public function hashSha256(BusinessStorageInterface $storage, string $relativePath): ?string
    {
        return $storage->checksum($relativePath);
    }

    public function materializeToDerived(BusinessStorageInterface $storage, string $assetUuid, string $kind, string $proxyPath): string
    {
        $baseName = pathinfo($proxyPath, PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($proxyPath, PATHINFO_EXTENSION));
        if ($extension === 'lrf') {
            $extension = 'mp4';
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $baseName) ?: $kind;
        $targetFileName = $safeName.'.'.$extension;
        $targetStoragePath = sprintf('.derived/%s/%s', $assetUuid, $targetFileName);

        if ($storage->fileExists($targetStoragePath)) {
            return $targetStoragePath;
        }

        if (!$storage->fileExists($proxyPath)) {
            throw new \RuntimeException(sprintf('Proxy source file not found: %s', $proxyPath));
        }

        try {
            $storage->move($proxyPath, $targetStoragePath);
        } catch (\Throwable) {
            $storage->copy($proxyPath, $targetStoragePath);
            $storage->deleteFile($proxyPath);
        }

        return $targetStoragePath;
    }
}
