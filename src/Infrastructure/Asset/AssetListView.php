<?php

namespace App\Infrastructure\Asset;

use App\Entity\Asset;

final class AssetListView
{
    public function __construct(
        private AssetDerivedViewProjector $derivedProjector,
    ) {
    }

    /**
     * @param list<Asset> $assets
     * @param list<string> $states
     * @param list<string> $tags
     * @param array{min_lon: float, min_lat: float, max_lon: float, max_lat: float}|null $geoBbox
     * @return list<Asset>
     */
    public function filterAndSort(
        array $assets,
        array $states,
        ?\DateTimeImmutable $capturedAtFrom,
        ?\DateTimeImmutable $capturedAtTo,
        array $tags,
        string $tagsMode,
        ?bool $hasPreview,
        ?string $locationCountry,
        ?string $locationCity,
        ?array $geoBbox,
        string $sort,
    ): array {
        $assets = $this->applyStateFilter($assets, $states);
        $assets = $this->applyCapturedAtRange($assets, $capturedAtFrom, $capturedAtTo);
        $assets = $this->applyTagsFilter($assets, $tags, $tagsMode);
        $assets = $this->applyHasPreviewFilter($assets, $hasPreview);
        $assets = $this->applyLocationFilter($assets, $locationCountry, $locationCity);
        $assets = $this->applyGeoBboxFilter($assets, $geoBbox);

        return $this->applySort($assets, $sort);
    }

    /**
     * @param list<Asset> $assets
     * @param list<string> $states
     * @return list<Asset>
     */
    private function applyStateFilter(array $assets, array $states): array
    {
        if ($states === []) {
            return $assets;
        }

        return array_values(array_filter(
            $assets,
            static fn (Asset $asset): bool => in_array($asset->getState()->value, $states, true)
        ));
    }

    /**
     * @param list<Asset> $assets
     * @param list<string> $tags
     * @return list<Asset>
     */
    private function applyTagsFilter(array $assets, array $tags, string $mode): array
    {
        if ($tags === []) {
            return $assets;
        }

        return array_values(array_filter($assets, fn (Asset $asset): bool => $this->matchesTags($asset, $tags, $mode)));
    }

    /**
     * @param list<Asset> $assets
     * @return list<Asset>
     */
    private function applyHasPreviewFilter(array $assets, ?bool $hasPreview): array
    {
        if ($hasPreview === null) {
            return $assets;
        }

        return array_values(array_filter(
            $assets,
            fn (Asset $asset): bool => $this->derivedProjector->hasPreview($asset) === $hasPreview
        ));
    }

    /**
     * @param list<Asset> $assets
     * @return list<Asset>
     */
    private function applyLocationFilter(array $assets, ?string $locationCountry, ?string $locationCity): array
    {
        if ($locationCountry === null && $locationCity === null) {
            return $assets;
        }

        return array_values(array_filter($assets, function (Asset $asset) use ($locationCountry, $locationCity): bool {
            $fields = $asset->getFields();
            $country = mb_strtolower(trim((string) ($fields['location_country'] ?? '')));
            $city = mb_strtolower(trim((string) ($fields['location_city'] ?? '')));

            if ($locationCountry !== null && $country !== mb_strtolower($locationCountry)) {
                return false;
            }

            if ($locationCity !== null && $city !== mb_strtolower($locationCity)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param list<string> $expected
     */
    private function matchesTags(Asset $asset, array $expected, string $mode): bool
    {
        $normalized = array_values(array_filter(
            array_map(static fn (string $tag): string => mb_strtolower(trim($tag)), $asset->getTags()),
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

    /**
     * @param list<Asset> $assets
     * @param array{min_lon: float, min_lat: float, max_lon: float, max_lat: float}|null $geoBbox
     * @return list<Asset>
     */
    private function applyGeoBboxFilter(array $assets, ?array $geoBbox): array
    {
        if ($geoBbox === null) {
            return $assets;
        }

        return array_values(array_filter($assets, function (Asset $asset) use ($geoBbox): bool {
            $fields = $asset->getFields();
            $lon = $this->optionalNumber($fields['gps_longitude'] ?? null);
            $lat = $this->optionalNumber($fields['gps_latitude'] ?? null);
            if ($lon === null || $lat === null) {
                return false;
            }

            return $lon >= $geoBbox['min_lon']
                && $lon <= $geoBbox['max_lon']
                && $lat >= $geoBbox['min_lat']
                && $lat <= $geoBbox['max_lat'];
        }));
    }

    /**
     * @param list<Asset> $assets
     * @return list<Asset>
     */
    private function applyCapturedAtRange(array $assets, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        if (!$from instanceof \DateTimeImmutable && !$to instanceof \DateTimeImmutable) {
            return $assets;
        }

        return array_values(array_filter($assets, function (Asset $asset) use ($from, $to): bool {
            $capturedAt = $this->capturedAt($asset);
            if (!$capturedAt instanceof \DateTimeImmutable) {
                return false;
            }

            if ($from instanceof \DateTimeImmutable && $capturedAt < $from) {
                return false;
            }

            if ($to instanceof \DateTimeImmutable && $capturedAt > $to) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param list<Asset> $assets
     * @return list<Asset>
     */
    private function applySort(array $assets, string $sort): array
    {
        $direction = str_starts_with($sort, '-') ? -1 : 1;
        $field = ltrim($sort, '-');

        usort($assets, function (Asset $left, Asset $right) use ($field, $direction): int {
            $comparison = $this->compareByField($left, $right, $field);
            if ($comparison !== 0) {
                return $comparison * $direction;
            }

            return strcmp($left->getUuid(), $right->getUuid());
        });

        return $assets;
    }

    private function compareByField(Asset $left, Asset $right, string $field): int
    {
        $leftValue = $this->sortValue($left, $field);
        $rightValue = $this->sortValue($right, $field);

        if ($leftValue === $rightValue) {
            return 0;
        }

        if ($leftValue === null) {
            return 1;
        }

        if ($rightValue === null) {
            return -1;
        }

        if (is_numeric($leftValue) && is_numeric($rightValue)) {
            return $leftValue <=> $rightValue;
        }

        return strcmp((string) $leftValue, (string) $rightValue);
    }

    private function sortValue(Asset $asset, string $field): string|float|int|null
    {
        $fields = $asset->getFields();

        return match ($field) {
            'name' => mb_strtolower($asset->getFilename()),
            'created_at' => $asset->getCreatedAt()->getTimestamp(),
            'updated_at' => $asset->getUpdatedAt()->getTimestamp(),
            'captured_at' => $this->capturedAt($asset)?->getTimestamp(),
            'duration' => is_numeric($fields['duration'] ?? null) ? (float) $fields['duration'] : null,
            'media_type' => mb_strtolower($asset->getMediaType()),
            'state' => $asset->getState()->value,
            default => $asset->getCreatedAt()->getTimestamp(),
        };
    }

    private function capturedAt(Asset $asset): ?\DateTimeImmutable
    {
        $fields = $asset->getFields();
        $value = $fields['captured_at'] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function optionalNumber(mixed $value): float|int|null
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }

        return $value;
    }
}
