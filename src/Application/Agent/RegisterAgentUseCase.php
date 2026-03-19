<?php

namespace App\Application\Agent;

interface RegisterAgentUseCase
{
    public function handle(string $actorId, string $agentId, string $agentName, string $clientContractVersion): RegisterAgentResult;
}
