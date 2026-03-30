<?php

namespace App\Tests\Unit\Api\Service\AgentSignature;

use App\Api\Service\AgentSignature\GpgCliAgentRequestSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class GpgCliAgentRequestSignatureVerifierTest extends TestCase
{
    public function testVerifyRejectsInvalidInputsBeforeTouchingGpg(): void
    {
        $verifier = new GpgCliAgentRequestSignatureVerifier();

        self::assertFalse($verifier->verify('', 'fingerprint', 'message', 'signature'));
        self::assertFalse($verifier->verify('PUBLIC KEY', '', 'message', 'signature'));
        self::assertFalse($verifier->verify('PUBLIC KEY', 'ABCD', 'message', '***not-base64***'));
    }
}
