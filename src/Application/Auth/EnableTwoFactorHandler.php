<?php

namespace App\Application\Auth;

use App\Application\Auth\Port\TwoFactorGateway;

final class EnableTwoFactorHandler
{
    public function __construct(
        private TwoFactorGateway $gateway,
    ) {
    }

    public function handle(string $userId, string $otpCode): EnableTwoFactorResult
    {
        try {
            $enabled = $this->gateway->enable($userId, $otpCode);
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'MFA_ALREADY_ENABLED') {
                return new EnableTwoFactorResult(EnableTwoFactorResult::STATUS_ALREADY_ENABLED);
            }

            return new EnableTwoFactorResult(EnableTwoFactorResult::STATUS_SETUP_REQUIRED);
        }

        if (!$enabled) {
            return new EnableTwoFactorResult(EnableTwoFactorResult::STATUS_INVALID_CODE);
        }

        return new EnableTwoFactorResult(EnableTwoFactorResult::STATUS_ENABLED);
    }
}
