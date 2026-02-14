<?php

namespace App\Application\Auth;

final class ConfirmEmailVerificationResult
{
    public const STATUS_VERIFIED = 'VERIFIED';
    public const STATUS_INVALID_TOKEN = 'INVALID_TOKEN';

    public function __construct(
        private string $status,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }
}
