<?php

namespace App\Storage;

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
        $configReader = new BusinessStorageEnvConfigReader($this->projectDir);
        $configs = $configReader->readAll();

        $storages = [];
        foreach ($configs as $config) {
            $storages[] = new BusinessStorageDefinition(
                $config->id,
                $this->buildStorage($config),
                $config->ingestEnabled,
            );
        }

        return new BusinessStorageRegistry($configReader->resolveDefaultStorageId($configs), $storages);
    }

    private function buildStorage(BusinessStorageEnvConfig $config): BusinessStorageInterface
    {
        return match ($config->driver) {
            'local' => $this->buildLocalStorage($config),
            'smb' => $this->buildSmbStorage($config),
            default => throw new \RuntimeException(sprintf('Unsupported business storage driver "%s".', $config->driver)),
        };
    }

    private function buildLocalStorage(BusinessStorageEnvConfig $config): BusinessStorageInterface
    {
        $config = new BusinessStorageConfig(
            $config->rootPath ?? '',
            $config->watchDirectory,
            $config->managedDirectories
        );

        return (new LocalBusinessStorageFactory($config))->create();
    }

    private function buildSmbStorage(BusinessStorageEnvConfig $config): BusinessStorageInterface
    {
        $storageConfig = new BusinessStorageConfig(
            $this->smbDisplayRoot($config->host ?? '', $config->share ?? '', $config->rootPrefix ?? ''),
            $config->watchDirectory,
            $config->managedDirectories
        );

        return (new SmbBusinessStorageFactory(
            $storageConfig,
            $config->host ?? '',
            $config->share ?? '',
            $config->username ?? '',
            $config->password ?? '',
            $config->workgroup,
            $config->rootPrefix ?? '',
            $config->minProtocol,
            $config->maxProtocol,
            $config->timeoutSeconds,
        ))->create();
    }

    private function smbDisplayRoot(string $host, string $share, string $rootPrefix): string
    {
        $normalizedPrefix = trim(str_replace('\\', '/', $rootPrefix), '/');

        return $normalizedPrefix === ''
            ? sprintf('smb://%s/%s', $host, $share)
            : sprintf('smb://%s/%s/%s', $host, $share, $normalizedPrefix);
    }
}
