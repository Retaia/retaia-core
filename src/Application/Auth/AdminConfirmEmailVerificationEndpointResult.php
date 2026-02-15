<?php

namespace App\Application\Auth;

final class AdminConfirmEmailVerificationEndpointResult
{
    public const STATUS_FORBIDDEN_ACTOR = 'FORBIDDEN_ACTOR';
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const STATUS_USER_NOT_FOUND = 'USER_NOT_FOUND';
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
