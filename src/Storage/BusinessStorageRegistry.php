<?php

namespace App\Storage;

final class BusinessStorageRegistry implements BusinessStorageRegistryInterface
{
    /**
     * @param list<BusinessStorageDefinition> $definitions
     */
    public function __construct(
        private string $defaultStorageId,
        private array $definitions,
    ) {
        if ($this->definitions === []) {
            throw new \InvalidArgumentException('At least one business storage definition is required.');
        }

        $indexed = [];
        foreach ($this->definitions as $definition) {
            if (isset($indexed[$definition->id])) {
                throw new \InvalidArgumentException(sprintf('Duplicate business storage id: %s', $definition->id));
            }
            $indexed[$definition->id] = $definition;
        }

        if (!isset($indexed[$this->defaultStorageId])) {
            throw new \InvalidArgumentException(sprintf('Unknown default business storage id: %s', $this->defaultStorageId));
        }

        $this->definitions = array_values($indexed);
    }

    public function defaultStorageId(): string
    {
        return $this->defaultStorageId;
    }

    public function all(): array
    {
        return $this->definitions;
    }

    public function ingestEnabled(): array
    {
        return array_values(array_filter(
            $this->definitions,
            static fn (BusinessStorageDefinition $definition): bool => $definition->ingestEnabled
        ));
    }

    public function has(string $storageId): bool
    {
        foreach ($this->definitions as $definition) {
            if ($definition->id === $storageId) {
                return true;
            }
        }

        return false;
    }

    public function get(string $storageId): BusinessStorageDefinition
    {
        foreach ($this->definitions as $definition) {
            if ($definition->id === $storageId) {
                return $definition;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown business storage id: %s', $storageId));
    }
}
