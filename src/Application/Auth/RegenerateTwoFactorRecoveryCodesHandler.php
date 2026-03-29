<?php

namespace App\Application\Auth;

use App\Application\Auth\Port\TwoFactorGateway;

final class RegenerateTwoFactorRecoveryCodesHandler
{
    public function __construct(
        private TwoFactorGateway $gateway,
    ) {
    }

    public function handle(string $userId, string $otpCode): RegenerateTwoFactorRecoveryCodesResult
    {
        try {
            if (!$this->gateway->verifyOtp($userId, $otpCode)) {
                return new RegenerateTwoFactorRecoveryCodesResult(
                    RegenerateTwoFactorRecoveryCodesResult::STATUS_INVALID_CODE
                );
            }

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
