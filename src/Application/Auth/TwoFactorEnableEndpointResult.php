<?php

namespace App\Application\Auth;

final class TwoFactorEnableEndpointResult
{
    public const STATUS_UNAUTHORIZED = 'UNAUTHORIZED';
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const STATUS_ALREADY_ENABLED = 'ALREADY_ENABLED';
    public const STATUS_SETUP_REQUIRED = 'SETUP_REQUIRED';
    public const STATUS_INVALID_CODE = 'INVALID_CODE';
    public const STATUS_ENABLED = 'ENABLED';

    public function __construct(
        private string $status,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }
}
