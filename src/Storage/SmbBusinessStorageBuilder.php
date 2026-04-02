<?php

namespace App\Storage;

final class SmbBusinessStorageBuilder implements BusinessStorageDriverBuilderInterface
{
    /**
     * @var \Closure(BusinessStorageConfig, BusinessStorageEnvConfig): BusinessStorageInterface
     */
    private \Closure $factory;

    /**
     * @param null|\Closure(BusinessStorageConfig, BusinessStorageEnvConfig): BusinessStorageInterface $factory
     */
    public function __construct(?\Closure $factory = null)
    {
        $this->factory = $factory ?? static fn (BusinessStorageConfig $storageConfig, BusinessStorageEnvConfig $config): BusinessStorageInterface => (new SmbBusinessStorageFactory(
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

    public function supports(string $driver): bool
    {
        return $driver === 'smb';
    }

    public function build(BusinessStorageEnvConfig $config): BusinessStorageInterface
    {
        $storageConfig = new BusinessStorageConfig(
            $this->displayRoot($config->host ?? '', $config->share ?? '', $config->rootPrefix ?? ''),
            $config->watchDirectory,
            $config->managedDirectories
        );

        return ($this->factory)($storageConfig, $config);
    }

    private function displayRoot(string $host, string $share, string $rootPrefix): string
    {
        $normalizedPrefix = trim(str_replace('\\', '/', $rootPrefix), '/');

        return $normalizedPrefix === ''
            ? sprintf('smb://%s/%s', $host, $share)
            : sprintf('smb://%s/%s/%s', $host, $share, $normalizedPrefix);
    }
}
