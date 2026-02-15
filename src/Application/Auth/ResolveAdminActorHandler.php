<?php

namespace App\Application\Auth;

use App\Application\Auth\Port\AdminActorGateway;

final class ResolveAdminActorHandler
{
    public function __construct(
        private AdminActorGateway $gateway,
    ) {
    }

    public function handle(): ResolveAdminActorResult
    {
        if (!$this->gateway->isAdmin()) {
            return new ResolveAdminActorResult(ResolveAdminActorResult::STATUS_FORBIDDEN_ACTOR);
        }

        return new ResolveAdminActorResult(ResolveAdminActorResult::STATUS_AUTHORIZED, $this->gateway->actorId());
    }
}
