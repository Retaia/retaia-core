<?php

namespace App\Storage;

final class LocalBusinessStorageBuilder implements BusinessStorageDriverBuilderInterface
{
    /**
     * @var \Closure(BusinessStorageConfig): BusinessStorageInterface
     */
    private \Closure $factory;

    /**
     * @param null|\Closure(BusinessStorageConfig): BusinessStorageInterface $factory
     */
    public function __construct(?\Closure $factory = null)
    {
        $this->factory = $factory ?? static fn (BusinessStorageConfig $config): BusinessStorageInterface => (new LocalBusinessStorageFactory($config))->create();
    }

    public function supports(string $driver): bool
    {
        return $driver === 'local';
    }

    public function build(BusinessStorageEnvConfig $config): BusinessStorageInterface
    {
        $storageConfig = new BusinessStorageConfig(
            $config->rootPath ?? '',
            $config->watchDirectory,
            $config->managedDirectories
        );

        return ($this->factory)($storageConfig);
    }
}
