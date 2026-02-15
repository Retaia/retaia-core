<?php

namespace App\Application\Auth;

final class RequestEmailVerificationEndpointResult
{
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const STATUS_TOO_MANY_ATTEMPTS = 'TOO_MANY_ATTEMPTS';
    public const STATUS_ACCEPTED = 'ACCEPTED';

    public function __construct(
        private string $status,
        private ?string $token = null,
        private ?int $retryInSeconds = null,
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

    public function retryInSeconds(): ?int
    {
        return $this->retryInSeconds;
    }
}
