<?php

namespace App\Application\Asset;

final class GetAssetResult
{
    public const STATUS_FOUND = 'FOUND';
    public const STATUS_NOT_FOUND = 'NOT_FOUND';

    /**
     * @param array<string, mixed>|null $asset
     */
    public function __construct(
        private string $status,
        private ?array $asset = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function asset(): ?array
    {
        return $this->asset;
    }
}
