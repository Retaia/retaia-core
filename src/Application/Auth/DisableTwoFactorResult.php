<?php

namespace App\Application\Auth;

final class DisableTwoFactorResult
{
    public const STATUS_DISABLED = 'DISABLED';
    public const STATUS_INVALID_CODE = 'INVALID_CODE';
    public const STATUS_NOT_ENABLED = 'NOT_ENABLED';

    public function __construct(
        private string $status,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }
}
