<?php

namespace App\Application\Auth;

use App\Application\Auth\Port\TwoFactorGateway;

final class DisableTwoFactorHandler
{
    public function __construct(
        private TwoFactorGateway $gateway,
    ) {
    }

    public function handle(string $userId, string $otpCode): DisableTwoFactorResult
    {
        try {
            $disabled = $this->gateway->disable($userId, $otpCode);
        } catch (\RuntimeException) {
            return new DisableTwoFactorResult(DisableTwoFactorResult::STATUS_NOT_ENABLED);
        }

        if (!$disabled) {
            return new DisableTwoFactorResult(DisableTwoFactorResult::STATUS_INVALID_CODE);
        }

        return new DisableTwoFactorResult(DisableTwoFactorResult::STATUS_DISABLED);
    }
}
