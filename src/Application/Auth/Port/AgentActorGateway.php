<?php

namespace App\Application\Auth\Port;

interface AgentActorGateway
{
    public function isAgent(): bool;
}
