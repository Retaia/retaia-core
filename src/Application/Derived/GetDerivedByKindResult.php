<?php

namespace App\Application\Derived;

final class GetDerivedByKindResult
{
    public const STATUS_ASSET_NOT_FOUND = 'ASSET_NOT_FOUND';
    public const STATUS_DERIVED_NOT_FOUND = 'DERIVED_NOT_FOUND';
    public const STATUS_FOUND = 'FOUND';

    /**
     * @param array<string, mixed>|null $derived
     */
    public function __construct(
        private string $status,
        private ?array $derived = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function derived(): ?array
    {
        return $this->derived;
    }
}
