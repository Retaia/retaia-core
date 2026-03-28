<?php

namespace App\Storage;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class BusinessStorageRegistryFactory
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function create(): BusinessStorageRegistryInterface
    {
        $ids = $this->configuredStorageIds();

        $storages = [];
        foreach ($ids as $id) {
            $driver = strtolower($this->requiredStorageValue($id, 'DRIVER'));
            $rootPath = $this->requiredStorageValue($id, 'ROOT_PATH');
            $watchDirectory = $this->storageValue($id, 'WATCH_DIRECTORY', 'INBOX');
            $managedDirectories = $this->storageListValue($id, 'MANAGED_DIRECTORIES');
            $ingestEnabled = $this->storageBoolValue($id, 'INGEST_ENABLED', true);

            if ($driver !== 'local') {
                throw new \RuntimeException(sprintf('Unsupported business storage driver "%s".', $driver));
            }

            $config = new BusinessStorageConfig(
                $this->normalizeRootPath($rootPath),
                $watchDirectory,
                $managedDirectories
            );

            $storages[] = new BusinessStorageDefinition(
                $id,
                new FlysystemBusinessStorage(
                    new Filesystem(new LocalFilesystemAdapter($config->rootPath(), null, LOCK_EX, LocalFilesystemAdapter::SKIP_LINKS)),
                    $config,
                ),
                $ingestEnabled,
            );
        }

        return new BusinessStorageRegistry($this->resolveDefaultStorageId($ids), $storages);
    }

    /**
     * @param list<string> $storageIds
     */
    private function resolveDefaultStorageId(array $storageIds): string
    {
        $configuredDefaultId = trim($this->envValue('APP_STORAGE_DEFAULT_ID', ''));
        if ($configuredDefaultId !== '') {
            if (!in_array($configuredDefaultId, $storageIds, true)) {
                throw new \RuntimeException(sprintf('APP_STORAGE_DEFAULT_ID references unknown business storage "%s".', $configuredDefaultId));
            }

            return $configuredDefaultId;
        }

        if (count($storageIds) === 1) {
            return $storageIds[0];
        }

        throw new \RuntimeException('APP_STORAGE_DEFAULT_ID must be configured explicitly when multiple business storages are declared.');
    }

    private function normalizeRootPath(string $rootPath): string
    {
        if ($rootPath === '') {
            return '';
        }

        if ($this->isAbsolute($rootPath)) {
            return $rootPath;
        }

        return rtrim($this->projectDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($rootPath, DIRECTORY_SEPARATOR);
    }

    private function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '/')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }

    /**
     * @return list<string>
     */
    private function configuredStorageIds(): array
    {
        $raw = trim($this->envValue('APP_STORAGE_IDS', ''));
        if ($raw === '') {
            throw new \RuntimeException('APP_STORAGE_IDS must be configured explicitly.');
        }

        $ids = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $raw)
        ), static fn (string $value): bool => $value !== ''));
        if ($ids === []) {
            throw new \RuntimeException('APP_STORAGE_IDS must declare at least one business storage id.');
        }

        if (count($ids) !== count(array_unique($ids))) {
            throw new \RuntimeException('APP_STORAGE_IDS contains duplicate business storage ids.');
        }

        $normalizedKeys = [];
        foreach ($ids as $id) {
            $normalizedKey = $this->storageKey($id);
            if (isset($normalizedKeys[$normalizedKey])) {
                throw new \RuntimeException(sprintf(
                    'Business storage ids "%s" and "%s" collide once normalized for environment variables.',
                    $normalizedKeys[$normalizedKey],
                    $id
                ));
            }
            $normalizedKeys[$normalizedKey] = $id;
        }

        return $ids;
    }

    private function requiredStorageValue(string $storageId, string $key): string
    {
        $value = trim($this->envValue($this->storageEnvName($storageId, $key), ''));
        if ($value === '') {
            throw new \RuntimeException(sprintf('Business storage "%s" requires %s.', $storageId, strtolower($key)));
        }

        return $value;
    }

    private function storageValue(string $storageId, string $key, string $default = ''): string
    {
        return trim($this->envValue($this->storageEnvName($storageId, $key), $default));
    }

    /**
     * @return list<string>|null
     */
    private function storageListValue(string $storageId, string $key): ?array
    {
        $raw = trim($this->envValue($this->storageEnvName($storageId, $key), ''));
        if ($raw === '') {
            return null;
        }

        $values = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $raw)
        ), static fn (string $value): bool => $value !== ''));

        return $values === [] ? null : $values;
    }

    private function storageBoolValue(string $storageId, string $key, bool $default): bool
    {
        $raw = trim($this->envValue($this->storageEnvName($storageId, $key), $default ? '1' : '0'));

        return !in_array(strtolower($raw), ['0', 'false', 'no', 'off'], true);
    }

    private function storageEnvName(string $storageId, string $suffix): string
    {
        return sprintf('APP_STORAGE_%s_%s', $this->storageKey($storageId), strtoupper($suffix));
    }

    private function storageKey(string $storageId): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $storageId) ?? '');
        $normalized = trim($normalized, '_');
        if ($normalized === '') {
            throw new \RuntimeException(sprintf('Business storage id "%s" cannot be normalized into an environment key.', $storageId));
        }

        return $normalized;
    }

    private function envValue(string $name, string $default): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if ($value === false || $value === null) {
            return $default;
        }

        return (string) $value;
    }
}
