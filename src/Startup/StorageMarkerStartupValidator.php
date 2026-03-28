<?php

namespace App\Startup;

use App\Storage\BusinessStorageRegistryInterface;

final class StorageMarkerStartupValidator
{
    private const MARKER_FILENAME = '.retaia';
    private const MARKER_VERSION = 1;

    private bool $validated = false;

    public function __construct(
        private BusinessStorageRegistryInterface $storageRegistry,
    ) {
    }

    public function validateStartup(): void
    {
        if ($this->validated) {
            return;
        }

        foreach ($this->storageRegistry->all() as $definition) {
            $markerPath = self::MARKER_FILENAME;

            if (!$definition->storage->fileExists($markerPath)) {
                $this->createMarkerAtomically($definition->storage, $definition->id, $markerPath);
            }

            $marker = $this->readMarker($definition->storage, $markerPath);
            $this->assertMarker($definition->id, $marker);
        }
        $this->validated = true;
    }

    private function createMarkerAtomically(\App\Storage\BusinessStorageInterface $storage, string $storageId, string $markerPath): void
    {
        $payload = [
            'version' => self::MARKER_VERSION,
            'storage_id' => $storageId,
            'paths' => [
                'inbox' => 'INBOX',
                'archive' => 'ARCHIVE',
                'rejects' => 'REJECTS',
            ],
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($encoded)) {
            throw new StorageMarkerStartupException('CORE_STORAGE_MARKER_CREATE_FAILED', 'Unable to encode marker payload as JSON.');
        }

        try {
            $storage->writeAtomically($markerPath, $encoded.PHP_EOL);
        } catch (\Throwable $exception) {
            throw new StorageMarkerStartupException(
                'CORE_STORAGE_MARKER_CREATE_FAILED',
                sprintf('Marker create/update failure: %s', $exception->getMessage()),
                $exception
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readMarker(\App\Storage\BusinessStorageInterface $storage, string $markerPath): array
    {
        try {
            $content = $storage->read($markerPath);
        } catch (\Throwable $exception) {
            throw new StorageMarkerStartupException(
                'CORE_STORAGE_MARKER_JSON_INVALID',
                sprintf('Unable to read marker file: %s', $markerPath),
                $exception
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new StorageMarkerStartupException(
                'CORE_STORAGE_MARKER_JSON_INVALID',
                sprintf('Marker contains invalid JSON: %s', $exception->getMessage()),
                $exception
            );
        }

        if (!is_array($decoded)) {
            throw new StorageMarkerStartupException('CORE_STORAGE_MARKER_JSON_INVALID', 'Marker root must be a JSON object.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $marker
     */
    private function assertMarker(string $expectedStorageId, array $marker): void
    {
        $version = $marker['version'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw new StorageMarkerStartupException('CORE_STORAGE_MARKER_JSON_INVALID', 'Marker version must be a strictly positive integer.');
        }
        if ($version !== self::MARKER_VERSION) {
            throw new StorageMarkerStartupException(
                'CORE_STORAGE_MARKER_SCHEMA_UPGRADE_FAILED',
                sprintf('Unsupported marker version %d. Expected %d.', $version, self::MARKER_VERSION)
            );
        }

        $storageId = $marker['storage_id'] ?? null;
        if (!is_string($storageId) || trim($storageId) === '') {
            throw new StorageMarkerStartupException('CORE_STORAGE_MARKER_JSON_INVALID', 'Marker storage_id must be a non-empty string.');
        }
        if ($storageId !== $expectedStorageId) {
            throw new StorageMarkerStartupException(
                'CORE_STORAGE_MARKER_STORAGE_ID_MISMATCH',
                sprintf('Marker storage_id "%s" does not match expected storage id "%s".', $storageId, $expectedStorageId)
            );
        }

        $paths = $marker['paths'] ?? null;
        if (!is_array($paths)) {
            throw new StorageMarkerStartupException('CORE_STORAGE_MARKER_JSON_INVALID', 'Marker paths must be an object.');
        }

        $required = ['inbox', 'archive', 'rejects'];
        sort($required);
        $keys = array_keys($paths);
        sort($keys);
        if ($keys !== $required) {
            throw new StorageMarkerStartupException('CORE_STORAGE_MARKER_JSON_INVALID', 'Marker paths must contain exactly inbox, archive, rejects.');
        }

        foreach ($required as $key) {
            $value = $paths[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                throw new StorageMarkerStartupException('CORE_STORAGE_MARKER_JSON_INVALID', sprintf('Marker paths.%s must be a non-empty string.', $key));
            }
            if ($this->isAbsolutePath($value) || str_contains($value, '..') || str_contains($value, "\0")) {
                throw new StorageMarkerStartupException('CORE_STORAGE_MARKER_JSON_INVALID', sprintf('Marker paths.%s must be a safe relative path.', $key));
            }
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }
}
