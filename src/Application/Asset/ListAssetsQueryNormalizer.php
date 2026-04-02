<?php

namespace App\Application\Asset;

use App\Asset\AssetState;

final class ListAssetsQueryNormalizer
{
    private const SORT_VALUES = [
        'name',
        '-name',
        'created_at',
        '-created_at',
        'updated_at',
        '-updated_at',
        'captured_at',
        '-captured_at',
        'duration',
        '-duration',
        'media_type',
        '-media_type',
        'state',
        '-state',
    ];

    /**
     * @param array<int, string> $states
     * @param array<int, string> $tags
     * @return array{
     *     states:list<string>,
     *     sort:string,
     *     capturedAtFrom:?\DateTimeImmutable,
     *     capturedAtTo:?\DateTimeImmutable,
     *     tagsMode:string,
     *     geoBbox:?array{min_lon: float, min_lat: float, max_lon: float, max_lat: float}
     * }|null
     */
    public function normalize(
        array $states,
        ?string $sort,
        ?string $capturedAtFrom,
        ?string $capturedAtTo,
        array $tags,
        string $tagsMode,
        ?string $geoBbox,
    ): ?array {
        $normalizedStates = $this->normalizeStates($states);
        if ($normalizedStates === null) {
            return null;
        }

        $normalizedTagsMode = strtoupper(trim($tagsMode));
        if (!in_array($normalizedTagsMode, ['AND', 'OR'], true)) {
            return null;
        }

        $normalizedGeoBbox = $this->parseGeoBbox($geoBbox);
        if ($geoBbox !== null && trim($geoBbox) !== '' && $normalizedGeoBbox === null) {
            return null;
        }

        $normalizedSort = $sort !== null && trim($sort) !== '' ? trim($sort) : '-created_at';
        if (!in_array($normalizedSort, self::SORT_VALUES, true)) {
            return null;
        }

        $from = $this->parseDateTime($capturedAtFrom);
        $to = $this->parseDateTime($capturedAtTo);
        if (($capturedAtFrom !== null && trim($capturedAtFrom) !== '' && $from === null)
            || ($capturedAtTo !== null && trim($capturedAtTo) !== '' && $to === null)
            || ($from !== null && $to !== null && $from > $to)
        ) {
            return null;
        }

        return [
            'states' => $normalizedStates,
            'sort' => $normalizedSort,
            'capturedAtFrom' => $from,
            'capturedAtTo' => $to,
            'tagsMode' => $normalizedTagsMode,
            'geoBbox' => $normalizedGeoBbox,
        ];
    }

    private function parseDateTime(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($value));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, string> $states
     * @return list<string>|null
     */
    private function normalizeStates(array $states): ?array
    {
        $normalized = [];
        foreach ($states as $state) {
            $value = strtoupper(trim($state));
            if ($value === '') {
                continue;
            }

            if (AssetState::tryFrom($value) === null) {
                return null;
            }

            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    /**
     * @return array{min_lon: float, min_lat: float, max_lon: float, max_lat: float}|null
     */
    private function parseGeoBbox(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $parts = array_map('trim', explode(',', $value));
        if (count($parts) !== 4) {
            return null;
        }

        foreach ($parts as $part) {
            if (!is_numeric($part)) {
                return null;
            }
        }

        [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $parts);
        if ($minLon < -180 || $maxLon > 180 || $minLat < -90 || $maxLat > 90) {
            return null;
        }
        if ($minLon >= $maxLon || $minLat >= $maxLat) {
            return null;
        }

        return [
            'min_lon' => $minLon,
            'min_lat' => $minLat,
            'max_lon' => $maxLon,
            'max_lat' => $maxLat,
        ];
    }
}
