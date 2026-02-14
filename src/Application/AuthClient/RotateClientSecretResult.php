<?php

namespace App\Application\AuthClient;

final class RotateClientSecretResult
{
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';

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

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
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
