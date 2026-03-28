<?php

namespace App\Storage;

final class BusinessStorageConfig
{
    private string $rootPath;
    private string $watchDirectory;
    /** @var list<string> */
    private array $managedDirectories;

    /**
     * @param list<string>|null $managedDirectories
     */
    public function __construct(string $rootPath, string $watchDirectory, ?array $managedDirectories = null)
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', trim($rootPath)), '/');
        if ($normalizedRoot === '') {
            throw new \RuntimeException('Business storage root path cannot be empty.');
        }

        $normalizedWatchDirectory = trim(str_replace('\\', '/', $watchDirectory), '/');
        if ($normalizedWatchDirectory === '' || $normalizedWatchDirectory === '.' || $normalizedWatchDirectory === '..') {
            throw new \RuntimeException(sprintf('Business storage watch directory must be a concrete relative path: %s', $watchDirectory));
        }

        $this->rootPath = $normalizedRoot;
        $this->watchDirectory = $normalizedWatchDirectory;
        $this->managedDirectories = $this->normalizeManagedDirectories($managedDirectories, $normalizedWatchDirectory);
    }

    public static function fromConfiguredWatchPath(string $projectDir, string $configuredWatchPath): self
    {
        $candidate = trim($configuredWatchPath);
        if ($candidate === '') {
            throw new \RuntimeException('Configured watch path cannot be empty.');
        }

        $absoluteWatchPath = self::normalizeAbsolutePath(self::toAbsolutePath($projectDir, $candidate));
        if ($absoluteWatchPath === '' || $absoluteWatchPath === '/') {
            throw new \RuntimeException(sprintf('Configured watch path must point to a concrete watch directory: %s', $configuredWatchPath));
        }
        $normalizedWatchPath = rtrim($absoluteWatchPath, '/');

        $rootPath = dirname($normalizedWatchPath);
        $watchDirectory = basename($normalizedWatchPath);
        if ($watchDirectory === '' || $watchDirectory === '.' || $watchDirectory === '..') {
            throw new \RuntimeException(sprintf('Configured watch path must point to a concrete watch directory: %s', $configuredWatchPath));
        }

        return new self($rootPath, $watchDirectory);
    }

    public function absoluteWatchPath(): string
    {
        return $this->rootPath.'/'.$this->watchDirectory;
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    public function watchDirectory(): string
    {
        return $this->watchDirectory;
    }

    /**
     * @return list<string>
     */
    public function managedDirectories(): array
    {
        return $this->managedDirectories;
    }

    private static function toAbsolutePath(string $projectDir, string $path): string
    {
        if ($path === '') {
            return '';
        }

        if (self::isAbsolute($path)) {
            return $path;
        }

        return rtrim($projectDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
    }

    private static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '/')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }

    private static function normalizeAbsolutePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        while (str_contains($normalized, '/./')) {
            $normalized = str_replace('/./', '/', $normalized);
        }

        return $normalized;
    }

    /**
     * @param list<string>|null $managedDirectories
     * @return list<string>
     */
    private function normalizeManagedDirectories(?array $managedDirectories, string $watchDirectory): array
    {
        $directories = $managedDirectories ?? [$watchDirectory, 'ARCHIVE', 'REJECTS'];
        $normalized = [];
        foreach ($directories as $directory) {
            $value = trim(str_replace('\\', '/', (string) $directory), '/');
            if ($value === '' || $value === '.' || $value === '..') {
                throw new \RuntimeException(sprintf('Managed storage directory must be a concrete relative path: %s', (string) $directory));
            }
            if (str_contains($value, '../') || str_contains($value, '..\\') || str_contains($value, "\0")) {
                throw new \RuntimeException(sprintf('Managed storage directory must be safe: %s', (string) $directory));
            }
            $normalized[] = $value;
        }

        $normalized = array_values(array_unique($normalized));
        if (!in_array($watchDirectory, $normalized, true)) {
            array_unshift($normalized, $watchDirectory);
            $normalized = array_values(array_unique($normalized));
        }

        return $normalized;
    }
}
