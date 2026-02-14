<?php

namespace App\Application\Auth;

final class RequestPasswordResetResult
{
    public const STATUS_ACCEPTED = 'ACCEPTED';

    public function __construct(
        private string $status,
        private ?string $token = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function token(): ?string
    {
        return $this->token;
    }
}
