<?php

namespace App\Storage;

use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;

final class FlysystemBusinessStorage implements BusinessStorageInterface
{
    public function __construct(
        private FilesystemOperator $filesystem,
        private BusinessStorageConfig $config,
    ) {
    }

    public function absoluteWatchPath(): string
    {
        return $this->config->absoluteWatchPath();
    }

    public function watchDirectory(): string
    {
        return $this->config->watchDirectory();
    }

    public function managedDirectories(): array
    {
        return $this->config->managedDirectories();
    }

    public function fileExists(string $path): bool
    {
        return $this->filesystem->fileExists($this->normalizePath($path));
    }

    public function directoryExists(string $path): bool
    {
        return $this->filesystem->directoryExists($this->normalizePath($path));
    }

    public function createDirectory(string $path): void
    {
        $normalized = $this->normalizePath($path);
        if ($normalized === '') {
            return;
        }

        $this->filesystem->createDirectory($normalized);
    }

    public function read(string $path): string
    {
        return $this->filesystem->read($this->normalizePath($path));
    }

    public function write(string $path, string $contents): void
    {
        $normalized = $this->normalizePath($path);
        $this->ensureParentDirectory($normalized);
        $this->filesystem->write($normalized, $contents);
    }

    public function writeAtomically(string $path, string $contents): void
    {
        $normalized = $this->normalizePath($path);
        $this->ensureParentDirectory($normalized);
        $tempPath = sprintf('%s.tmp.%s', $normalized, bin2hex(random_bytes(6)));
        $this->filesystem->write($tempPath, $contents);

        try {
            if ($this->filesystem->fileExists($normalized)) {
                $this->filesystem->delete($normalized);
            }
            $this->filesystem->move($tempPath, $normalized);
        } catch (\Throwable $exception) {
            if ($this->filesystem->fileExists($tempPath)) {
                $this->filesystem->delete($tempPath);
            }
            throw $exception;
        }
    }

    public function move(string $source, string $destination): void
    {
        $normalizedSource = $this->normalizePath($source);
        $normalizedDestination = $this->normalizePath($destination);
        $this->ensureParentDirectory($normalizedDestination);
        $this->filesystem->move($normalizedSource, $normalizedDestination);
    }

    public function copy(string $source, string $destination): void
    {
        $normalizedSource = $this->normalizePath($source);
        $normalizedDestination = $this->normalizePath($destination);
        $this->ensureParentDirectory($normalizedDestination);
        $this->filesystem->copy($normalizedSource, $normalizedDestination);
    }

    public function deleteFile(string $path): void
    {
        $normalized = $this->normalizePath($path);
        if (!$this->filesystem->fileExists($normalized)) {
            return;
        }

        $this->filesystem->delete($normalized);
    }

    public function deleteDirectory(string $path): void
    {
        $normalized = $this->normalizePath($path);
        if (!$this->filesystem->directoryExists($normalized)) {
            return;
        }

        $this->filesystem->deleteDirectory($normalized);
    }

    public function fileSize(string $path): int
    {
        return $this->filesystem->fileSize($this->normalizePath($path));
    }

    public function lastModified(string $path): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.$this->filesystem->lastModified($this->normalizePath($path)));
    }

    public function checksum(string $path, string $algorithm = 'sha256'): ?string
    {
        $normalized = $this->normalizePath($path);

        try {
            return $this->filesystem->checksum($normalized, ['checksum_algo' => $algorithm]);
        } catch (\Throwable) {
            $content = $this->filesystem->read($normalized);
            $hash = hash($algorithm, $content);

            return is_string($hash) && $hash !== '' ? $hash : null;
        }
    }

    public function listFiles(string $directory, bool $recursive = false): array
    {
        $files = $this->collectFiles($this->normalizePath($directory), $recursive);

        usort($files, static fn (BusinessStorageFile $left, BusinessStorageFile $right): int => strcmp($left->path, $right->path));

        return $files;
    }

    public function probeWritableDirectory(string $directory): bool
    {
        $normalizedDirectory = $this->normalizePath($directory);
        $probe = rtrim($normalizedDirectory, '/').'/.__retaia_probe_'.bin2hex(random_bytes(6));

        try {
            $this->write($probe, 'ok');
            $this->deleteFile($probe);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function ensureParentDirectory(string $path): void
    {
        $directory = trim(dirname($path), '.');
        $directory = $directory === '' ? '' : str_replace('\\', '/', $directory);
        if ($directory === '') {
            return;
        }

        $this->filesystem->createDirectory($directory);
    }

    /**
     * @return list<BusinessStorageFile>
     */
    private function collectFiles(string $directory, bool $recursive): array
    {
        $files = [];

        try {
            $listing = $this->filesystem->listContents($directory, false);
        } catch (\Throwable) {
            return [];
        }

        try {
            /** @var StorageAttributes $attributes */
            foreach ($listing as $attributes) {
                if ($attributes instanceof FileAttributes) {
                    $lastModified = $attributes->lastModified();
                    $files[] = new BusinessStorageFile(
                        $attributes->path(),
                        $attributes->fileSize() ?? $this->filesystem->fileSize($attributes->path()),
                        new \DateTimeImmutable('@'.($lastModified ?? $this->filesystem->lastModified($attributes->path()))),
                    );
                    continue;
                }

                if ($recursive && $attributes instanceof DirectoryAttributes) {
                    array_push($files, ...$this->collectFiles($attributes->path(), true));
                }
            }
        } catch (\Throwable) {
            return $files;
        }

        return $files;
    }

    private function normalizePath(string $path): string
    {
        $normalized = ltrim(str_replace('\\', '/', trim($path)), '/');
        if ($normalized === '' || str_contains($normalized, "\0") || str_contains($normalized, '../')) {
            throw new \InvalidArgumentException(sprintf('Unsafe storage path: %s', $path));
        }

        return $normalized;
    }
}
