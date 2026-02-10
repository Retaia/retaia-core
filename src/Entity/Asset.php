<?php

namespace App\Entity;

use App\Asset\AssetState;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'asset')]
#[ORM\UniqueConstraint(name: 'uniq_asset_uuid', columns: ['uuid'])]
class Asset
{
    /**
     * @param array<int, string> $tags
     * @param array<string, mixed> $fields
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 36)]
        private string $uuid,
        #[ORM\Column(name: 'media_type', type: 'string', length: 16)]
        private string $mediaType,
        #[ORM\Column(name: 'filename', type: 'string', length: 255)]
        private string $filename,
        #[ORM\Column(type: 'string', enumType: AssetState::class, length: 32)]
        private AssetState $state = AssetState::DISCOVERED,
        #[ORM\Column(type: 'json')]
        private array $tags = [],
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $notes = null,
        #[ORM\Column(type: 'json')]
        private array $fields = [],
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $updatedAt = new \DateTimeImmutable(),
    ) {
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getState(): AssetState
    {
        return $this->state;
    }

    public function setState(AssetState $state): void
    {
        $this->state = $state;
        $this->touch();
    }

    /**
     * @return array<int, string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @param array<int, string> $tags
     */
    public function setTags(array $tags): void
    {
        $this->tags = array_values(array_unique(array_map('strval', $tags)));
        $this->touch();
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): void
    {
        $this->notes = $notes;
        $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function setFields(array $fields): void
    {
        $this->fields = $fields;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
