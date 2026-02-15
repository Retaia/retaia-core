<?php

namespace App\Application\AuthClient;

final class RotateClientSecretEndpointResult
{
    public const STATUS_UNAUTHORIZED = 'UNAUTHORIZED';
    public const STATUS_FORBIDDEN_ACTOR = 'FORBIDDEN_ACTOR';
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const STATUS_SUCCESS = 'SUCCESS';

    public function __construct(
        private string $status,
        private ?string $secretKey = null,
        private ?string $clientKind = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function secretKey(): ?string
    {
        return $this->secretKey;
    }

    public function clientKind(): ?string
    {
        return $this->clientKind;
    }
}
