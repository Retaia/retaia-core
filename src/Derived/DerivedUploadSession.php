<?php

namespace App\Derived;

final class DerivedUploadSession
{
    public function __construct(
        public readonly string $uploadId,
        public readonly string $assetUuid,
        public readonly string $kind,
        public readonly string $contentType,
        public readonly int $sizeBytes,
        public readonly ?string $sha256,
        public readonly string $status,
        public readonly int $partsCount,
    ) {
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
