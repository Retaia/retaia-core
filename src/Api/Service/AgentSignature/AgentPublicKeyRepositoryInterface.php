<?php

namespace App\Api\Service\AgentSignature;

interface AgentPublicKeyRepositoryInterface
{
    public function findByAgentId(string $agentId): ?AgentPublicKeyRecord;

    public function findByAgentIdAndFingerprint(string $agentId, string $fingerprint): ?AgentPublicKeyRecord;

    public function save(AgentPublicKeyRecord $record): void;
}
