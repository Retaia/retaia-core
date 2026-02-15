<?php

namespace App\Application\Auth;

final class ConfirmEmailVerificationEndpointResult
{
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const STATUS_INVALID_TOKEN = 'INVALID_TOKEN';
    public const STATUS_VERIFIED = 'VERIFIED';

    public function __construct(
        private string $status,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }
}
