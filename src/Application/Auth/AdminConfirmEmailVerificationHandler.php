<?php

namespace App\Application\Auth;

use App\Application\Auth\Port\EmailVerificationGateway;

final class AdminConfirmEmailVerificationHandler
{
    public function __construct(
        private EmailVerificationGateway $gateway,
    ) {
    }

    public function handle(string $email, ?string $actorId): AdminConfirmEmailVerificationResult
    {
        if (!$this->gateway->forceVerifyByEmail($email, $actorId)) {
            return new AdminConfirmEmailVerificationResult(AdminConfirmEmailVerificationResult::STATUS_USER_NOT_FOUND);
        }

        return new AdminConfirmEmailVerificationResult(AdminConfirmEmailVerificationResult::STATUS_VERIFIED);
    }
}
