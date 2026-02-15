<?php

namespace App\Infrastructure\Asset;

use App\Application\Asset\Port\AssetReadGateway as AssetReadGatewayPort;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;

final class AssetReadGateway implements AssetReadGatewayPort
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private bool $featureSuggestedTagsFiltersEnabled,
    ) {
    }

    public function getByUuid(string $uuid): ?array
    {
        $asset = $this->assets->findByUuid($uuid);
        if (!$asset instanceof Asset) {
            return null;
        }

        return $this->detail($asset);
    }

    public function list(
        ?string $state,
        ?string $mediaType,
        ?string $query,
        int $limit,
        array $suggestedTags,
        string $suggestedTagsMode,
    ): ?array {
        if ($suggestedTags !== [] && !$this->featureSuggestedTagsFiltersEnabled) {
            return null;
        }

        $assets = $this->assets->listAssets($state, $mediaType, $query, $limit);
        if ($suggestedTags !== []) {
            $assets = array_values(array_filter(
                $assets,
                fn (Asset $asset): bool => $this->matchesSuggestedTags($asset, $suggestedTags, $suggestedTagsMode)
            ));
        }

        return array_map(fn (Asset $asset): array => $this->summary($asset), $assets);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Asset $asset): array
    {
        return [
            'uuid' => $asset->getUuid(),
            'media_type' => $asset->getMediaType(),
            'filename' => $asset->getFilename(),
            'state' => $asset->getState()->value,
            'tags' => $asset->getTags(),
            'notes' => $asset->getNotes(),
            'fields' => $asset->getFields(),
            'created_at' => $asset->getCreatedAt()->format(DATE_ATOM),
            'updated_at' => $asset->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Asset $asset): array
    {
        return [
            'uuid' => $asset->getUuid(),
            'media_type' => $asset->getMediaType(),
            'filename' => $asset->getFilename(),
            'state' => $asset->getState()->value,
            'tags' => $asset->getTags(),
        ];
    }

    /**
     * @param array<int, string> $expected
     */
    private function matchesSuggestedTags(Asset $asset, array $expected, string $mode): bool
    {
        $fields = $asset->getFields();
        $tags = [];
        if (is_array($fields['suggestions']['suggested_tags'] ?? null)) {
            $tags = $fields['suggestions']['suggested_tags'];
        } elseif (is_array($fields['suggested_tags'] ?? null)) {
            $tags = $fields['suggested_tags'];
        }

        $normalized = array_values(array_filter(
            array_map(static fn (mixed $tag): string => mb_strtolower(trim((string) $tag)), $tags),
            static fn (string $tag): bool => $tag !== ''
        ));

        if ($mode === 'OR') {
            foreach ($expected as $tag) {
                if (in_array($tag, $normalized, true)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($expected as $tag) {
            if (!in_array($tag, $normalized, true)) {
                return false;
            }
        }

        return true;
    }
}
