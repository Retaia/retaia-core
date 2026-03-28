<?php

namespace App\Api\Service\AgentSignature;

interface AgentSignatureNonceRepositoryInterface
{
    public function consume(string $agentId, string $nonce, int $ttlSeconds): bool;
}
