<?php

namespace App\Infrastructure\Auth;

use App\Application\Auth\Port\AgentActorGateway as AgentActorGatewayPort;
use Symfony\Bundle\SecurityBundle\Security;

final class AgentActorGateway implements AgentActorGatewayPort
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function isAgent(): bool
    {
        return $this->security->isGranted('ROLE_AGENT');
    }
}
