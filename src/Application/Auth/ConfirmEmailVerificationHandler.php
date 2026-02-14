<?php

namespace App\Application\Auth;

use App\Application\Auth\Port\EmailVerificationGateway;

final class ConfirmEmailVerificationHandler
{
    public function __construct(
        private EmailVerificationGateway $gateway,
    ) {
    }

    public function handle(string $token): ConfirmEmailVerificationResult
    {
        if (!$this->gateway->confirmVerification($token)) {
            return new ConfirmEmailVerificationResult(ConfirmEmailVerificationResult::STATUS_INVALID_TOKEN);
        }

        return new ConfirmEmailVerificationResult(ConfirmEmailVerificationResult::STATUS_VERIFIED);
    }
}
