<?php

namespace App\Application\Auth;

final class TwoFactorRecoveryCodesEndpointResult
{
    public const STATUS_UNAUTHORIZED = 'UNAUTHORIZED';
    public const STATUS_NOT_ENABLED = 'NOT_ENABLED';
    public const STATUS_REGENERATED = 'REGENERATED';

    /**
     * @param list<string> $recoveryCodes
     */
    public function __construct(
        private string $status,
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
