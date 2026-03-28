<?php

namespace App\Storage;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

final class LocalBusinessStorageFactory
{
    public function __construct(
        private BusinessStorageConfig $config,
    ) {
    }

    public function create(): BusinessStorageInterface
    {
        return new FlysystemBusinessStorage(
            new Filesystem(new LocalFilesystemAdapter($this->config->rootPath(), null, LOCK_EX, LocalFilesystemAdapter::SKIP_LINKS)),
            $this->config,
        );
    }
}
