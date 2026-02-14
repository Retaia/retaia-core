<?php

namespace App\Application\Auth;

final class AdminConfirmEmailVerificationResult
{
    public const STATUS_VERIFIED = 'VERIFIED';
    public const STATUS_USER_NOT_FOUND = 'USER_NOT_FOUND';

    public function __construct(
        private string $status,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }
}
