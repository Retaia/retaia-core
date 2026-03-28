<?php

namespace App\Storage;

final readonly class BusinessStorageDefinition
{
    public function __construct(
        public string $id,
        public BusinessStorageInterface $storage,
        public bool $ingestEnabled = true,
    ) {
        if (trim($this->id) === '') {
            throw new \InvalidArgumentException('Business storage definition id cannot be empty.');
        }
    }
}
