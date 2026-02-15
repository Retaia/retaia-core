<?php

namespace App\Application\Workflow;

final class ApplyDecisionsResult
{
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const STATUS_APPLIED = 'APPLIED';

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
