<?php

namespace App\Application\Auth;

use App\Application\Auth\Port\TwoFactorGateway;

final class SetupTwoFactorHandler
{
    public function __construct(
        private TwoFactorGateway $gateway,
    ) {
    }

    public function handle(string $userId, string $email): SetupTwoFactorResult
    {
        try {
            $setup = $this->gateway->setup($userId, $email);
        } catch (\RuntimeException) {
            return new SetupTwoFactorResult(SetupTwoFactorResult::STATUS_ALREADY_ENABLED);
        }

        return new SetupTwoFactorResult(SetupTwoFactorResult::STATUS_READY, $setup);
    }
}
