<?php

namespace App\Derived;

final class DerivedFile
{
    public function __construct(
        public readonly string $id,
        public readonly string $assetUuid,
        public readonly string $kind,
        public readonly string $contentType,
        public readonly int $sizeBytes,
        public readonly ?string $sha256,
        public readonly string $storagePath,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }
}
