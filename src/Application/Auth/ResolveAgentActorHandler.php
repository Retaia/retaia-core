<?php

namespace App\Application\Auth;

use App\Application\Auth\Port\AgentActorGateway;

final class ResolveAgentActorHandler
{
    public function __construct(
        private AgentActorGateway $gateway,
    ) {
    }

    public function handle(): ResolveAgentActorResult
    {
        if (!$this->gateway->isAgent()) {
            return new ResolveAgentActorResult(ResolveAgentActorResult::STATUS_FORBIDDEN_ACTOR);
        }

        return new ResolveAgentActorResult(ResolveAgentActorResult::STATUS_AUTHORIZED);
    }
}
