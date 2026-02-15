<?php

namespace App\Application\Derived;

final class ListDerivedFilesResult
{
    public const STATUS_NOT_FOUND = 'NOT_FOUND';
    public const STATUS_FOUND = 'FOUND';

    /**
     * @param array<int, array<string, mixed>>|null $items
     */
    public function __construct(
        private string $status,
        private ?array $items = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function items(): ?array
    {
        return $this->items;
    }
}
