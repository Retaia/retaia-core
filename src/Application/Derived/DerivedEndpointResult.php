<?php

namespace App\Application\Derived;

final class DerivedEndpointResult
{
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FORBIDDEN_ACTOR = 'FORBIDDEN_ACTOR';
    public const STATUS_NOT_FOUND = 'NOT_FOUND';
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const STATUS_STATE_CONFLICT = 'STATE_CONFLICT';

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
