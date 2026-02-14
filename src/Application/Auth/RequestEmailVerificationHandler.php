<?php

namespace App\Application\Auth;

use App\Application\Auth\Port\EmailVerificationGateway;

final class RequestEmailVerificationHandler
{
    public function __construct(
        private EmailVerificationGateway $gateway,
    ) {
    }

    public function handle(string $email): RequestEmailVerificationResult
    {
        return new RequestEmailVerificationResult(
            RequestEmailVerificationResult::STATUS_ACCEPTED,
            $this->gateway->requestVerification($email)
        );
    }
}
