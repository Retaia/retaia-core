<?php

namespace App\Application\Auth;

final class RegenerateTwoFactorRecoveryCodesResult
{
    public const STATUS_REGENERATED = 'REGENERATED';
    public const STATUS_NOT_ENABLED = 'NOT_ENABLED';
    public const STATUS_INVALID_CODE = 'INVALID_CODE';

    /**
     * @param list<string> $codes
     */
    public function __construct(
        private string $status,
        private array $codes = [],
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return $this->codes;
    }
}
