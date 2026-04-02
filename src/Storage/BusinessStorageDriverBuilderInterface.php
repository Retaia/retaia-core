<?php

namespace App\Storage;

interface BusinessStorageDriverBuilderInterface
{
    public function supports(string $driver): bool;

    public function build(BusinessStorageEnvConfig $config): BusinessStorageInterface;
}
