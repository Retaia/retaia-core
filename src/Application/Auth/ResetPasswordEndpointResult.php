<?php

namespace App\Application\Auth;

final class ResetPasswordEndpointResult
{
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const STATUS_INVALID_TOKEN = 'INVALID_TOKEN';
    public const STATUS_PASSWORD_RESET = 'PASSWORD_RESET';

    /**
     * @param list<string> $violations
     */
    public function __construct(
        private string $status,
        private array $violations = [],
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return list<string>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
