<?php

namespace App\Storage;

interface BusinessStorageInterface
{
    public function absoluteWatchPath(): string;

    public function watchDirectory(): string;

    /**
     * @return list<string>
     */
    public function managedDirectories(): array;

    public function fileExists(string $path): bool;

    public function directoryExists(string $path): bool;

    public function createDirectory(string $path): void;

    public function read(string $path): string;

    public function write(string $path, string $contents): void;

    public function writeAtomically(string $path, string $contents): void;

    public function move(string $source, string $destination): void;

    public function copy(string $source, string $destination): void;

    public function deleteFile(string $path): void;

    public function deleteDirectory(string $path): void;

    public function fileSize(string $path): int;

    public function lastModified(string $path): \DateTimeImmutable;

    public function checksum(string $path, string $algorithm = 'sha256'): ?string;

    /**
     * @return list<BusinessStorageFile>
     */
    public function listFiles(string $directory, bool $recursive = false): array;

    public function probeWritableDirectory(string $directory): bool;
}
