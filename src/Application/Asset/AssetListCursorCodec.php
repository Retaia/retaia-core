<?php

namespace App\Application\Asset;

final class AssetListCursorCodec
{
    /**
     * @param array<int, string> $states
     * @param array<int, string> $tags
     * @param array{min_lon: float, min_lat: float, max_lon: float, max_lat: float}|null $geoBbox
     */
    public function contextHash(
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

    public function encode(int $offset, string $contextHash): string
    {
        return rtrim(strtr(base64_encode(json_encode([
            'offset' => $offset,
            'context_hash' => $contextHash,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    public function decodeOffset(?string $cursor, string $contextHash): ?int
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
