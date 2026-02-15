<?php

namespace App\Application\Auth;

final class RegenerateTwoFactorRecoveryCodesResult
{
    public const STATUS_REGENERATED = 'REGENERATED';
    public const STATUS_NOT_ENABLED = 'NOT_ENABLED';

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
