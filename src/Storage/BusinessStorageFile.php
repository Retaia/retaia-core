<?php

namespace App\Storage;

final readonly class BusinessStorageFile
{
    public function __construct(
        public string $path,
        public int $size,
        public \DateTimeImmutable $lastModified,
    ) {
    }
}
