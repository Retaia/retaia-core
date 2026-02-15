<?php

namespace App\Application\Auth;

final class EnableTwoFactorResult
{
    public const STATUS_ENABLED = 'ENABLED';
    public const STATUS_INVALID_CODE = 'INVALID_CODE';
    public const STATUS_ALREADY_ENABLED = 'ALREADY_ENABLED';
    public const STATUS_SETUP_REQUIRED = 'SETUP_REQUIRED';

    public function __construct(
        private string $status,
        /** @var list<string> */
        private array $recoveryCodes = [],
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return list<string>
     */
    public function recoveryCodes(): array
    {
        return $this->recoveryCodes;
    }
}
