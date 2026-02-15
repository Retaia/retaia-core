<?php

namespace App\Application\Asset;

final class DecideAssetResult
{
    public const STATUS_NOT_FOUND = 'NOT_FOUND';
    public const STATUS_STATE_CONFLICT = 'STATE_CONFLICT';
    public const STATUS_VALIDATION_FAILED_ACTION_REQUIRED = 'VALIDATION_FAILED_ACTION_REQUIRED';
    public const STATUS_DECIDED = 'DECIDED';

    /**
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        private string $status,
        private ?array $payload = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payload(): ?array
    {
        return $this->payload;
    }
}
