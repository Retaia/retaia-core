<?php

namespace App\Application\Auth;

use App\Application\Auth\Port\PasswordResetGateway;

final class RequestPasswordResetHandler
{
    public function __construct(
        private PasswordResetGateway $gateway,
    ) {
    }

    public function handle(string $email): RequestPasswordResetResult
    {
        return new RequestPasswordResetResult(
            RequestPasswordResetResult::STATUS_ACCEPTED,
            $this->gateway->requestReset($email)
        );
    }
}
