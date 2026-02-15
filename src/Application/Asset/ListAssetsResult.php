<?php

namespace App\Application\Asset;

final class ListAssetsResult
{
    public const STATUS_OK = 'OK';
    public const STATUS_FORBIDDEN_SCOPE = 'FORBIDDEN_SCOPE';
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';

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
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        return $this->items ?? [];
    }
}
