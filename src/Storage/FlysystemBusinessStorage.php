<?php

namespace App\Storage;

use League\Flysystem\FilesystemOperator;

final class FlysystemBusinessStorage implements BusinessStorageInterface
{
    public function __construct(
        private FilesystemOperator $filesystem,
        private BusinessStorageConfig $config,
        private StoragePathNormalizer $paths = new StoragePathNormalizer(),
        private FlysystemAtomicWriter $writer = new FlysystemAtomicWriter(new StoragePathNormalizer()),
        private FlysystemFileCollector $collector = new FlysystemFileCollector(),
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
        return $this->filesystem->fileExists($this->paths->normalize($path));
    }

    public function directoryExists(string $path): bool
    {
        return $this->filesystem->directoryExists($this->paths->normalize($path));
    }

    public function createDirectory(string $path): void
    {
        $normalized = $this->paths->normalize($path);
        if ($normalized === '') {
            return;
        }

        $this->filesystem->createDirectory($normalized);
    }

    public function read(string $path): string
    {
        return $this->filesystem->read($this->paths->normalize($path));
    }

    public function write(string $path, string $contents): void
    {
        $normalized = $this->paths->normalize($path);
        $this->writer->write($this->filesystem, $normalized, $contents);
    }

    public function writeAtomically(string $path, string $contents): void
    {
        $normalized = $this->paths->normalize($path);
        $this->writer->writeAtomically($this->filesystem, $normalized, $contents);
    }

    public function move(string $source, string $destination): void
    {
        $normalizedSource = $this->paths->normalize($source);
        $normalizedDestination = $this->paths->normalize($destination);
        $this->paths->ensureParentDirectory($normalizedDestination, $this->filesystem->createDirectory(...));
        $this->filesystem->move($normalizedSource, $normalizedDestination);
    }

    public function copy(string $source, string $destination): void
    {
        $normalizedSource = $this->paths->normalize($source);
        $normalizedDestination = $this->paths->normalize($destination);
        $this->paths->ensureParentDirectory($normalizedDestination, $this->filesystem->createDirectory(...));
        $this->filesystem->copy($normalizedSource, $normalizedDestination);
    }

    public function deleteFile(string $path): void
    {
        $normalized = $this->paths->normalize($path);
        if (!$this->filesystem->fileExists($normalized)) {
            return;
        }

        $this->filesystem->delete($normalized);
    }

    public function deleteDirectory(string $path): void
    {
        $normalized = $this->paths->normalize($path);
        if (!$this->filesystem->directoryExists($normalized)) {
            return;
        }

        $this->filesystem->deleteDirectory($normalized);
    }

    public function fileSize(string $path): int
    {
        return $this->filesystem->fileSize($this->paths->normalize($path));
    }

    public function lastModified(string $path): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.$this->filesystem->lastModified($this->paths->normalize($path)));
    }

    public function checksum(string $path, string $algorithm = 'sha256'): ?string
    {
        $normalized = $this->paths->normalize($path);

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
        $files = $this->collector->collect($this->filesystem, $this->paths->normalize($directory), $recursive);

        usort($files, static fn (BusinessStorageFile $left, BusinessStorageFile $right): int => strcmp($left->path, $right->path));

        return $files;
    }

    public function probeWritableDirectory(string $directory): bool
    {
        $normalizedDirectory = $this->paths->normalize($directory);

        return $this->writer->probeWritableDirectory(
            $this->filesystem,
            $normalizedDirectory,
            $this->write(...),
            $this->deleteFile(...)
        );
    }
}
