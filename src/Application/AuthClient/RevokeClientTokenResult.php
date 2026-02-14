<?php

namespace App\Application\AuthClient;

final class RevokeClientTokenResult
{
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FORBIDDEN_SCOPE = 'FORBIDDEN_SCOPE';
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';

    public function __construct(
        private string $status,
        private ?string $clientKind = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function clientKind(): ?string
    {
        return $this->clientKind;
    }
}
