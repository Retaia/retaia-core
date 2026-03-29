<?php

namespace App\Derived;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'asset_derived_file')]
final class DerivedFile
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'id', type: 'string', length: 16)]
        public string $id,
        #[ORM\Column(name: 'asset_uuid', type: 'string', length: 36)]
        public string $assetUuid,
        #[ORM\Column(name: 'kind', type: 'string', length: 64)]
        public string $kind,
        #[ORM\Column(name: 'content_type', type: 'string', length: 128)]
        public string $contentType,
        #[ORM\Column(name: 'size_bytes', type: 'integer')]
        public int $sizeBytes,
        #[ORM\Column(name: 'sha256', type: 'string', length: 64, nullable: true)]
        public ?string $sha256,
        #[ORM\Column(name: 'storage_path', type: 'string', length: 255)]
        public string $storagePath,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public function syncMaterialized(string $contentType, int $sizeBytes, ?string $sha256, string $storagePath): void
    {
        $this->contentType = $contentType;
        $this->sizeBytes = $sizeBytes;
        $this->sha256 = $sha256;
        $this->storagePath = $storagePath;
    }
}
