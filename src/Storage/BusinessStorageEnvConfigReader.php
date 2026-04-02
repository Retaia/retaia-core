<?php

namespace App\Storage;

final class BusinessStorageEnvConfigReader
{
    private const SMB_PROTOCOLS = [
        'NT1',
        'SMB2',
        'SMB2_02',
        'SMB2_22',
        'SMB2_24',
        'SMB3',
        'SMB3_00',
        'SMB3_02',
        'SMB3_10',
        'SMB3_11',
    ];

    public function __construct(
        private string $projectDir,
    ) {
    }

    /**
     * @return list<BusinessStorageEnvConfig>
     */
    public function readAll(): array
    {
        $ids = $this->configuredStorageIds();
        $configs = [];

        foreach ($ids as $id) {
            $configs[] = $this->readOne($id);
        }

        return $configs;
    }

    /**
     * @param list<BusinessStorageEnvConfig> $configs
     */
    public function resolveDefaultStorageId(array $configs): string
    {
        $storageIds = array_map(static fn (BusinessStorageEnvConfig $config): string => $config->id, $configs);

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

    public function normalizeRootPath(string $rootPath): string
    {
        if ($rootPath === '') {
            return '';
        }

        if ($this->isAbsolute($rootPath)) {
            return $rootPath;
        }

        return rtrim($this->projectDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($rootPath, DIRECTORY_SEPARATOR);
    }

    private function readOne(string $storageId): BusinessStorageEnvConfig
    {
        $driver = strtolower($this->requiredStorageValue($storageId, 'DRIVER'));
        $watchDirectory = $this->storageValue($storageId, 'WATCH_DIRECTORY', 'INBOX');
        $managedDirectories = $this->storageListValue($storageId, 'MANAGED_DIRECTORIES');
        $ingestEnabled = $this->storageBoolValue($storageId, 'INGEST_ENABLED', true);

        if ($driver === 'local') {
            return new BusinessStorageEnvConfig(
                $storageId,
                $driver,
                $watchDirectory,
                $managedDirectories,
                $ingestEnabled,
                rootPath: $this->normalizeRootPath($this->requiredStorageValue($storageId, 'ROOT_PATH')),
            );
        }

        if ($driver === 'smb') {
            return new BusinessStorageEnvConfig(
                $storageId,
                $driver,
                $watchDirectory,
                $managedDirectories,
                $ingestEnabled,
                host: $this->requiredStorageValue($storageId, 'HOST'),
                share: $this->requiredStorageValue($storageId, 'SHARE'),
                username: $this->requiredStorageValue($storageId, 'USERNAME'),
                password: $this->requiredStorageValue($storageId, 'PASSWORD'),
                workgroup: $this->nullableStorageValue($storageId, 'WORKGROUP'),
                rootPrefix: $this->storageValue($storageId, 'ROOT_PATH', ''),
                minProtocol: $this->protocolValue($storageId, 'SMB_VERSION_MIN'),
                maxProtocol: $this->protocolValue($storageId, 'SMB_VERSION_MAX'),
                timeoutSeconds: $this->storageIntValue($storageId, 'TIMEOUT_SECONDS', 20, 1),
            );
        }

        throw new \RuntimeException(sprintf('Unsupported business storage driver "%s".', $driver));
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

    private function nullableStorageValue(string $storageId, string $key): ?string
    {
        $value = trim($this->envValue($this->storageEnvName($storageId, $key), ''));

        return $value === '' ? null : $value;
    }

    private function storageIntValue(string $storageId, string $key, int $default, int $min): int
    {
        $value = trim($this->envValue($this->storageEnvName($storageId, $key), (string) $default));
        if ($value === '' || !ctype_digit($value)) {
            throw new \RuntimeException(sprintf('Business storage "%s" requires %s to be an integer >= %d.', $storageId, strtolower($key), $min));
        }

        $parsed = (int) $value;
        if ($parsed < $min) {
            throw new \RuntimeException(sprintf('Business storage "%s" requires %s to be an integer >= %d.', $storageId, strtolower($key), $min));
        }

        return $parsed;
    }

    private function protocolValue(string $storageId, string $key): ?string
    {
        $value = strtoupper(trim($this->envValue($this->storageEnvName($storageId, $key), '')));
        if ($value === '') {
            return null;
        }
        if (!in_array($value, self::SMB_PROTOCOLS, true)) {
            throw new \RuntimeException(sprintf(
                'Business storage "%s" requires %s to be one of: %s.',
                $storageId,
                strtolower($key),
                implode(', ', self::SMB_PROTOCOLS)
            ));
        }

        return $value;
    }
}
