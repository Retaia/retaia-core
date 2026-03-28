<?php

namespace App\Ingest\Service;

final class ExistingProxyFilesystem implements ExistingProxyFilesystemInterface
{
    public function isFile(string $root, string $relativePath): bool
    {
        return is_file($this->absolutePath($root, $relativePath));
    }

    public function fileSize(string $root, string $relativePath): int
    {
        $size = filesize($this->absolutePath($root, $relativePath));

        return is_int($size) ? $size : 0;
    }

    public function hashSha256(string $root, string $relativePath): ?string
    {
        $hash = hash_file('sha256', $this->absolutePath($root, $relativePath));

        return is_string($hash) ? $hash : null;
    }

    public function materializeToDerived(string $root, string $assetUuid, string $kind, string $proxyPath): string
    {
        $baseName = pathinfo($proxyPath, PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($proxyPath, PATHINFO_EXTENSION));
        if ($extension === 'lrf') {
            $extension = 'mp4';
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $baseName) ?: $kind;
        $targetFileName = $safeName.'.'.$extension;
        $targetStoragePath = sprintf('.derived/%s/%s', $assetUuid, $targetFileName);
        $targetAbsolutePath = $this->absolutePath($root, $targetStoragePath);
        $targetDirectory = dirname($targetAbsolutePath);

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create derived directory for %s', $targetStoragePath));
        }

        if (is_file($targetAbsolutePath)) {
            return $targetStoragePath;
        }

        $sourceAbsolutePath = $this->absolutePath($root, $proxyPath);
        if (!is_file($sourceAbsolutePath)) {
            throw new \RuntimeException(sprintf('Proxy source file not found: %s', $proxyPath));
        }

        if (@rename($sourceAbsolutePath, $targetAbsolutePath)) {
            return $targetStoragePath;
        }

        if (!@copy($sourceAbsolutePath, $targetAbsolutePath)) {
            throw new \RuntimeException(sprintf('Unable to move proxy %s into %s', $proxyPath, $targetStoragePath));
        }
        @unlink($sourceAbsolutePath);

        return $targetStoragePath;
    }

    private function absolutePath(string $root, string $relativePath): string
    {
        return rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($relativePath, DIRECTORY_SEPARATOR);
    }
}
