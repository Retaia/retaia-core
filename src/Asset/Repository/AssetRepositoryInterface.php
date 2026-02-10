<?php

namespace App\Asset\Repository;

use App\Entity\Asset;

interface AssetRepositoryInterface
{
    public function findByUuid(string $uuid): ?Asset;

    /**
     * @return array<int, Asset>
     */
    public function listAssets(
        ?string $state,
        ?string $mediaType,
        ?string $query,
        int $limit,
    ): array;

    public function save(Asset $asset): void;
}
