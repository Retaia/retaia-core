<?php

namespace App\Application\Asset\Port;

interface AssetReadGateway
{
    /**
     * @return array<string, mixed>|null
     */
    public function getByUuid(string $uuid): ?array;

    /**
     * @param array<int, string> $suggestedTags
     * @return array<int, array<string, mixed>>|null
     */
    public function list(
        ?string $state,
        ?string $mediaType,
        ?string $query,
        ?string $sort,
        ?\DateTimeImmutable $capturedAtFrom,
        ?\DateTimeImmutable $capturedAtTo,
        int $limit,
        array $suggestedTags,
        string $suggestedTagsMode,
    ): ?array;
}
