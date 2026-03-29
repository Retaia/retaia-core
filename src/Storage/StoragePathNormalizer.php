<?php

namespace App\Storage;

final class StoragePathNormalizer
{
    public function normalize(string $path): string
    {
        $normalized = ltrim(str_replace('\\', '/', trim($path)), '/');
        if ($normalized === '' || str_contains($normalized, "\0") || str_contains($normalized, '../')) {
            throw new \InvalidArgumentException(sprintf('Unsafe storage path: %s', $path));
        }

        return $normalized;
    }

    public function ensureParentDirectory(string $path, callable $createDirectory): void
    {
        $directory = trim(dirname($path), '.');
        $directory = $directory === '' ? '' : str_replace('\\', '/', $directory);
        if ($directory === '') {
            return;
        }

        $createDirectory($directory);
    }
}
