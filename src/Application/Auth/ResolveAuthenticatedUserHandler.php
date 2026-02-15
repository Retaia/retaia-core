<?php

namespace App\Application\Auth;

use App\Application\Auth\Port\AuthenticatedUserGateway;

final class ResolveAuthenticatedUserHandler implements ResolveAuthenticatedUserUseCase
{
    public function __construct(
        private AuthenticatedUserGateway $gateway,
    ) {
    }

    public function handle(): ResolveAuthenticatedUserResult
    {
        $currentUser = $this->gateway->currentUser();
        if ($currentUser === null) {
            return new ResolveAuthenticatedUserResult(ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED);
        }

        return new ResolveAuthenticatedUserResult(
            ResolveAuthenticatedUserResult::STATUS_AUTHENTICATED,
            $currentUser['id'],
            $currentUser['email'],
            $currentUser['roles']
        );
    }
}
