<?php

namespace App\Storage;

final class StoragePathNormalizer
{
    public function normalize(string $path): string
    {
        $normalized = ltrim(str_replace('\\', '/', trim($path)), '/');
        $decoded = rawurldecode($normalized);

        if ($normalized === '' || str_contains($normalized, "\0") || str_contains($decoded, "\0")) {
            throw new \InvalidArgumentException(sprintf('Unsafe storage path: %s', $path));
        }

        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '..') {
                throw new \InvalidArgumentException(sprintf('Unsafe storage path: %s', $path));
            }
        }

        return $normalized;
    }

    public function ensureParentDirectory(string $path, callable $createDirectory): void
    {
        $directory = trim(dirname($this->normalize($path)), '.');
        if ($directory === '') {
            return;
        }

        $createDirectory($directory);
    }
}
