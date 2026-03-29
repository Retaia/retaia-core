<?php

namespace App\Derived;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'derived_upload_session')]
final class DerivedUploadSession
{
    #[ORM\Id]
    #[ORM\Column(name: 'upload_id', type: 'string', length: 24)]
    public string $uploadId;

    #[ORM\Column(name: 'asset_uuid', type: 'string', length: 36)]
    public string $assetUuid;

    #[ORM\Column(type: 'string', length: 64)]
    public string $kind;

    #[ORM\Column(name: 'content_type', type: 'string', length: 128)]
    public string $contentType;

    #[ORM\Column(name: 'size_bytes', type: 'integer')]
    public int $sizeBytes;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    public ?string $sha256;

    #[ORM\Column(type: 'string', length: 16)]
    public string $status;

    #[ORM\Column(name: 'parts_count', type: 'integer')]
    public int $partsCount;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;

    public function __construct(
        string $uploadId,
        string $assetUuid,
        string $kind,
        string $contentType,
        int $sizeBytes,
        ?string $sha256,
        string $status,
        int $partsCount,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $now = new \DateTimeImmutable();
        $this->uploadId = $uploadId;
        $this->assetUuid = $assetUuid;
        $this->kind = $kind;
        $this->contentType = $contentType;
        $this->sizeBytes = $sizeBytes;
        $this->sha256 = $sha256;
        $this->status = $status;
        $this->partsCount = $partsCount;
        $this->createdAt = $createdAt ?? $now;
        $this->updatedAt = $updatedAt ?? $this->createdAt;
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function updateHighestPartCount(int $partNumber, \DateTimeImmutable $updatedAt): void
    {
        if ($partNumber > $this->partsCount) {
            $this->partsCount = $partNumber;
        }

        $this->updatedAt = $updatedAt;
    }

    public function markCompleted(\DateTimeImmutable $updatedAt): void
    {
        $this->status = 'completed';
        $this->updatedAt = $updatedAt;
    }
}
