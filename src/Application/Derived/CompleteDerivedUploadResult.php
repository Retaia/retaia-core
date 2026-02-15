<?php

namespace App\Application\Derived;

final class CompleteDerivedUploadResult
{
    public const STATUS_NOT_FOUND = 'NOT_FOUND';
    public const STATUS_STATE_CONFLICT = 'STATE_CONFLICT';
    public const STATUS_COMPLETED = 'COMPLETED';

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
