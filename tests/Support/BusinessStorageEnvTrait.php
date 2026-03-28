<?php

namespace App\Tests\Support;

trait BusinessStorageEnvTrait
{
    private function configureSingleLocalBusinessStorage(string $rootPath, string $storageId = 'nas-main', string $watchDirectory = 'INBOX'): void
    {
        $this->configureBusinessStorages([[
            'id' => $storageId,
            'root_path' => $rootPath,
            'watch_directory' => $watchDirectory,
        ]], $storageId);
    }

    /**
     * @param list<array{
     *     id:string,
     *     driver?:string,
     *     root_path?:string,
     *     watch_directory?:string,
     *     ingest_enabled?:bool,
     *     managed_directories?:list<string>,
     *     host?:string,
     *     share?:string,
     *     username?:string,
     *     password?:string,
     *     workgroup?:string,
     *     timeout_seconds?:int,
     *     smb_version_min?:string,
     *     smb_version_max?:string
     * }> $storages
     */
    private function configureBusinessStorages(array $storages, ?string $defaultStorageId = null): void
    {
        $this->clearBusinessStorageEnv();

        $storageIds = array_map(static fn (array $storage): string => $storage['id'], $storages);
        $resolvedDefaultStorageId = $defaultStorageId ?? (count($storageIds) === 1 ? $storageIds[0] : null);
        if ($resolvedDefaultStorageId === null) {
            throw new \InvalidArgumentException('A default business storage id is required when configuring multiple storages.');
        }

        $this->setStorageEnv('APP_STORAGE_IDS', implode(',', $storageIds));
        $this->setStorageEnv('APP_STORAGE_DEFAULT_ID', $resolvedDefaultStorageId);

        foreach ($storages as $storage) {
            $storageId = $storage['id'];
            $prefix = 'APP_STORAGE_'.$this->storageEnvKey($storageId).'_';

            $this->setStorageEnv($prefix.'DRIVER', $storage['driver'] ?? 'local');
            if (array_key_exists('root_path', $storage)) {
                $this->setStorageEnv($prefix.'ROOT_PATH', (string) $storage['root_path']);
            }
            $this->setStorageEnv($prefix.'WATCH_DIRECTORY', $storage['watch_directory'] ?? 'INBOX');

            if (array_key_exists('ingest_enabled', $storage)) {
                $this->setStorageEnv($prefix.'INGEST_ENABLED', $storage['ingest_enabled'] ? '1' : '0');
            }

            if (array_key_exists('managed_directories', $storage)) {
                $this->setStorageEnv($prefix.'MANAGED_DIRECTORIES', implode(',', $storage['managed_directories']));
            }

            foreach ([
                'host' => 'HOST',
                'share' => 'SHARE',
                'username' => 'USERNAME',
                'password' => 'PASSWORD',
                'workgroup' => 'WORKGROUP',
                'timeout_seconds' => 'TIMEOUT_SECONDS',
                'smb_version_min' => 'SMB_VERSION_MIN',
                'smb_version_max' => 'SMB_VERSION_MAX',
            ] as $field => $suffix) {
                if (array_key_exists($field, $storage)) {
                    $this->setStorageEnv($prefix.$suffix, (string) $storage[$field]);
                }
            }
        }
    }

    private function clearBusinessStorageEnv(): void
    {
        foreach (array_keys($_ENV) as $name) {
            if (str_starts_with($name, 'APP_STORAGE_')) {
                unset($_ENV[$name]);
            }
        }

        foreach (array_keys($_SERVER) as $name) {
            if (str_starts_with($name, 'APP_STORAGE_')) {
                unset($_SERVER[$name]);
            }
        }

        foreach (array_keys(getenv()) as $name) {
            if (is_string($name) && str_starts_with($name, 'APP_STORAGE_')) {
                putenv($name);
            }
        }
    }

    private function setStorageEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function storageEnvKey(string $storageId): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $storageId) ?? '');
        $normalized = trim($normalized, '_');
        if ($normalized === '') {
            throw new \InvalidArgumentException(sprintf('Storage id "%s" cannot be normalized into an environment key.', $storageId));
        }

        return $normalized;
    }
}
