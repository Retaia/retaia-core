<?php

namespace App\Application\Asset;

use App\Asset\AssetState;
use App\Application\Asset\Port\AssetReadGateway;

final class ListAssetsHandler
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

    public function __construct(
        private AssetReadGateway $gateway,
    ) {
    }

    /**
     * @param array<int, string> $states
     * @param array<int, string> $tags
     */
    public function handle(
        array $states,
        ?string $mediaType,
        ?string $query,
        ?string $sort,
        ?string $capturedAtFrom,
        ?string $capturedAtTo,
        int $limit,
        ?string $cursor,
        array $tags,
        string $tagsMode,
        ?bool $hasPreview,
        ?string $locationCountry,
        ?string $locationCity,
        ?string $geoBbox,
    ): ListAssetsResult {
        $normalizedStates = $this->normalizeStates($states);
        if ($normalizedStates === null) {
            return new ListAssetsResult(ListAssetsResult::STATUS_VALIDATION_FAILED);
        }

        $normalizedTagsMode = strtoupper(trim($tagsMode));
        if (!in_array($normalizedTagsMode, ['AND', 'OR'], true)) {
            return new ListAssetsResult(ListAssetsResult::STATUS_VALIDATION_FAILED);
        }

        $normalizedGeoBbox = $this->parseGeoBbox($geoBbox);
        if ($geoBbox !== null && trim($geoBbox) !== '' && $normalizedGeoBbox === null) {
            return new ListAssetsResult(ListAssetsResult::STATUS_VALIDATION_FAILED);
        }
        $normalizedSort = $sort !== null && trim($sort) !== '' ? trim($sort) : '-created_at';
        if (!in_array($normalizedSort, self::SORT_VALUES, true)) {
            return new ListAssetsResult(ListAssetsResult::STATUS_VALIDATION_FAILED);
        }

        $from = $this->parseDateTime($capturedAtFrom);
        $to = $this->parseDateTime($capturedAtTo);
        if (($capturedAtFrom !== null && trim($capturedAtFrom) !== '' && $from === null)
            || ($capturedAtTo !== null && trim($capturedAtTo) !== '' && $to === null)
            || ($from !== null && $to !== null && $from > $to)
        ) {
            return new ListAssetsResult(ListAssetsResult::STATUS_VALIDATION_FAILED);
        }

        $cursorOffset = $this->decodeCursorOffset($cursor, $this->cursorContextHash(
            $normalizedStates,
            $mediaType,
            $query,
            $normalizedSort,
            $from,
            $to,
            $limit,
            $tags,
            $normalizedTagsMode,
            $hasPreview,
            $locationCountry,
            $locationCity,
            $normalizedGeoBbox,
        ));
        if ($cursor !== null && trim($cursor) !== '' && $cursorOffset === null) {
            return new ListAssetsResult(ListAssetsResult::STATUS_VALIDATION_FAILED);
        }

        $page = $this->gateway->list(
            $normalizedStates,
            $mediaType,
            $query,
            $normalizedSort,
            $from,
            $to,
            $limit,
            $cursorOffset ?? 0,
            $tags,
            $normalizedTagsMode,
            $hasPreview,
            $locationCountry,
            $locationCity,
            $normalizedGeoBbox,
        );
        if ($page === null) {
            return new ListAssetsResult(ListAssetsResult::STATUS_FORBIDDEN_SCOPE);
        }

        $items = $page['items'] ?? [];
        $nextCursor = ($page['has_more'] ?? false) === true
            ? $this->encodeCursor(($cursorOffset ?? 0) + count($items), $this->cursorContextHash(
                $normalizedStates,
                $mediaType,
                $query,
                $normalizedSort,
                $from,
                $to,
                $limit,
                $tags,
                $normalizedTagsMode,
                $hasPreview,
                $locationCountry,
                $locationCity,
                $normalizedGeoBbox,
            ))
            : null;

        return new ListAssetsResult(ListAssetsResult::STATUS_OK, $items, $nextCursor);
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
     * @return array<int, string>|null
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

    /**
     * @param array<int, string> $states
     * @param array<int, string> $tags
     * @param array{min_lon: float, min_lat: float, max_lon: float, max_lat: float}|null $geoBbox
     */
    private function cursorContextHash(
        array $states,
        ?string $mediaType,
        ?string $query,
        string $sort,
        ?\DateTimeImmutable $capturedAtFrom,
        ?\DateTimeImmutable $capturedAtTo,
        int $limit,
        array $tags,
        string $tagsMode,
        ?bool $hasPreview,
        ?string $locationCountry,
        ?string $locationCity,
        ?array $geoBbox,
    ): string {
        return hash('sha256', json_encode([
            'states' => $states,
            'media_type' => $mediaType !== null ? strtoupper(trim($mediaType)) : null,
            'query' => $query !== null ? trim($query) : null,
            'sort' => $sort,
            'captured_at_from' => $capturedAtFrom?->format(DATE_ATOM),
            'captured_at_to' => $capturedAtTo?->format(DATE_ATOM),
            'limit' => $limit,
            'tags' => $tags,
            'tags_mode' => $tagsMode,
            'has_preview' => $hasPreview,
            'location_country' => $locationCountry,
            'location_city' => $locationCity,
            'geo_bbox' => $geoBbox,
        ], JSON_THROW_ON_ERROR));
    }

    private function encodeCursor(int $offset, string $contextHash): string
    {
        return rtrim(strtr(base64_encode(json_encode([
            'offset' => $offset,
            'context_hash' => $contextHash,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function decodeCursorOffset(?string $cursor, string $contextHash): ?int
    {
        if ($cursor === null || trim($cursor) === '') {
            return 0;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!is_string($decoded)) {
            return null;
        }

        try {
            $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        $offset = $payload['offset'] ?? null;
        $cursorHash = $payload['context_hash'] ?? null;
        if (!is_int($offset) || $offset < 0 || !is_string($cursorHash) || $cursorHash !== $contextHash) {
            return null;
        }

        return $offset;
    }
}
