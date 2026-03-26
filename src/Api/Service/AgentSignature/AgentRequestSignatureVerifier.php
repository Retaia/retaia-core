<?php

namespace App\Api\Service\AgentSignature;

interface AgentRequestSignatureVerifier
{
    public function verify(string $publicKey, string $expectedFingerprint, string $message, string $signature): bool;
}
