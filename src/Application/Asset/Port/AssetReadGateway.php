<?php

namespace App\Application\Asset\Port;

interface AssetReadGateway
{
    /**
     * @return array<string, mixed>|null
     */
    public function getByUuid(string $uuid): ?array;

    /**
     * @param array<int, string> $states
     * @param array<int, string> $tags
     * @param array{min_lon: float, min_lat: float, max_lon: float, max_lat: float}|null $geoBbox
     * @return array{items: array<int, array<string, mixed>>, has_more: bool}|null
     */
    public function list(
        array $states,
        ?string $mediaType,
        ?string $query,
        ?string $sort,
        ?\DateTimeImmutable $capturedAtFrom,
        ?\DateTimeImmutable $capturedAtTo,
        int $limit,
        int $offset,
        array $tags,
        string $tagsMode,
        ?bool $hasPreview,
        ?string $locationCountry,
        ?string $locationCity,
        ?array $geoBbox,
    ): ?array;
}
