<?php

namespace App\Storage;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class BusinessStorageRegistryFactory
{
    /** @var list<BusinessStorageDriverBuilderInterface>|null */
    private ?array $builders = null;

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
        foreach ($this->builders() as $builder) {
            if ($builder->supports($config->driver)) {
                return $builder->build($config);
            }
        }

        throw new \RuntimeException(sprintf('Unsupported business storage driver "%s".', $config->driver));
    }

    /**
     * @return list<BusinessStorageDriverBuilderInterface>
     */
    private function builders(): array
    {
        if (is_array($this->builders)) {
            return $this->builders;
        }

        return $this->builders = [
            new LocalBusinessStorageBuilder(),
            new SmbBusinessStorageBuilder(),
        ];
    }
}
