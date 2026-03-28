<?php

namespace App\Storage;

interface BusinessStorageRegistryInterface
{
    public function defaultStorageId(): string;

    /**
     * @return list<BusinessStorageDefinition>
     */
    public function all(): array;

    /**
     * @return list<BusinessStorageDefinition>
     */
    public function ingestEnabled(): array;

    public function has(string $storageId): bool;

    public function get(string $storageId): BusinessStorageDefinition;
}
