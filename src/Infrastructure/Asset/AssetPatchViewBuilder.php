<?php

namespace App\Infrastructure\Asset;

use App\Asset\AssetRevisionTag;
use App\Entity\Asset;

final class AssetPatchViewBuilder
{
    private const HIDDEN_FIELD_KEYS = ['projects'];

    public function __construct(
        private AssetProjectsNormalizer $projects,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Asset $asset): array
    {
        return [
            'uuid' => $asset->getUuid(),
            'media_type' => $asset->getMediaType(),
            'filename' => $asset->getFilename(),
            'state' => $asset->getState()->value,
            'tags' => $asset->getTags(),
            'notes' => $asset->getNotes(),
            'fields' => $this->publicFields($asset->getFields()),
            'projects' => $this->projects->fromFields($asset->getFields()),
            'created_at' => $asset->getCreatedAt()->format(DATE_ATOM),
            'updated_at' => $asset->getUpdatedAt()->format(DATE_ATOM),
            'revision_etag' => AssetRevisionTag::fromAsset($asset),
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function publicFields(array $fields): array
    {
        foreach (self::HIDDEN_FIELD_KEYS as $key) {
            unset($fields[$key]);
        }

        return $fields;
    }
}
