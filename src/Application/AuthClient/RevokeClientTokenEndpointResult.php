<?php

namespace App\Application\AuthClient;

final class RevokeClientTokenEndpointResult
{
    public const STATUS_UNAUTHORIZED = 'UNAUTHORIZED';
    public const STATUS_FORBIDDEN_ACTOR = 'FORBIDDEN_ACTOR';
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const STATUS_FORBIDDEN_SCOPE = 'FORBIDDEN_SCOPE';
    public const STATUS_SUCCESS = 'SUCCESS';

    public function __construct(
        private string $status,
        private ?string $clientKind = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function clientKind(): ?string
    {
        return $this->clientKind;
    }
}
