<?php

namespace App\Application\Auth;

final class TwoFactorDisableEndpointResult
{
    public const STATUS_UNAUTHORIZED = 'UNAUTHORIZED';
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const STATUS_NOT_ENABLED = 'NOT_ENABLED';
    public const STATUS_INVALID_CODE = 'INVALID_CODE';
    public const STATUS_DISABLED = 'DISABLED';

    public function __construct(
        private string $status,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }
}
