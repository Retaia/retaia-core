<?php

namespace App\Application\Auth;

use App\Application\Auth\Port\TwoFactorGateway;

final class RegenerateTwoFactorRecoveryCodesHandler
{
    public function __construct(
        private TwoFactorGateway $gateway,
    ) {
    }

    public function handle(string $userId): RegenerateTwoFactorRecoveryCodesResult
    {
        try {
            $codes = $this->gateway->regenerateRecoveryCodes($userId);
        } catch (\RuntimeException) {
            return new RegenerateTwoFactorRecoveryCodesResult(
                RegenerateTwoFactorRecoveryCodesResult::STATUS_NOT_ENABLED
            );
        }

        return new RegenerateTwoFactorRecoveryCodesResult(
            RegenerateTwoFactorRecoveryCodesResult::STATUS_REGENERATED,
            $codes
        );
    }
}
