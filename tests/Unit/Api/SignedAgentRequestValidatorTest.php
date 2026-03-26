<?php

namespace App\Tests\Unit\Api;

use App\Api\Service\AgentSignature\AgentPublicKeyStore;
use App\Api\Service\AgentSignature\AgentRequestSignatureVerifier;
use App\Api\Service\AgentSignature\AgentSignatureNonceStore;
use App\Api\Service\AgentSignature\SignedAgentMessageCanonicalizer;
use App\Api\Service\SignedAgentRequestValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

final class SignedAgentRequestValidatorTest extends TestCase
{
    public function testRejectsMissingHeaders(): void
    {
        $validator = $this->validator(new AlwaysValidVerifier());

        $response = $validator->violationResponse(Request::create('/api/v1/jobs/job-1/claim', 'POST'));

        self::assertNotNull($response);
        self::assertSame(['X-Retaia-Agent-Id', 'X-Retaia-OpenPGP-Fingerprint', 'X-Retaia-Signature', 'X-Retaia-Signature-Timestamp', 'X-Retaia-Signature-Nonce'], json_decode((string) $response->getContent(), true)['details']['invalid_headers'] ?? null);
    }

    public function testAcceptsValidSignatureWithPayloadPublicKey(): void
    {
        $validator = $this->validator(new AlwaysValidVerifier());
        $request = $this->signedRequest();
        $payload = [
            'agent_id' => self::agentId(),
            'openpgp_fingerprint' => self::fingerprint(),
            'openpgp_public_key' => 'public-key',
        ];

        self::assertNull($validator->violationResponse($request, $payload));
    }

    public function testRejectsForgedSignature(): void
    {
        $store = $this->keyStore();
        $store->register(self::agentId(), self::fingerprint(), 'public-key');
        $validator = new SignedAgentRequestValidator(
            $store,
            new AlwaysInvalidVerifier(),
            new AgentSignatureNonceStore(new ArrayAdapter()),
            new SignedAgentMessageCanonicalizer(),
        );

        $response = $validator->violationResponse($this->signedRequest());

        self::assertNotNull($response);
        self::assertSame(['X-Retaia-Signature'], json_decode((string) $response->getContent(), true)['details']['invalid_headers'] ?? null);
    }

    public function testRejectsReplayNonce(): void
    {
        $store = $this->keyStore();
        $store->register(self::agentId(), self::fingerprint(), 'public-key');
        $validator = new SignedAgentRequestValidator(
            $store,
            new AlwaysValidVerifier(),
            new AgentSignatureNonceStore(new ArrayAdapter()),
            new SignedAgentMessageCanonicalizer(),
        );

        self::assertNull($validator->violationResponse($this->signedRequest('nonce-1')));
        $response = $validator->violationResponse($this->signedRequest('nonce-1'));

        self::assertNotNull($response);
        self::assertSame(['X-Retaia-Signature-Nonce'], json_decode((string) $response->getContent(), true)['details']['invalid_headers'] ?? null);
    }

    public function testRejectsUnknownRegisteredAgentKey(): void
    {
        $validator = $this->validator(new AlwaysValidVerifier());

        $response = $validator->violationResponse($this->signedRequest());

        self::assertNotNull($response);
        self::assertSame(['X-Retaia-OpenPGP-Fingerprint'], json_decode((string) $response->getContent(), true)['details']['invalid_headers'] ?? null);
    }

    private function validator(AgentRequestSignatureVerifier $verifier): SignedAgentRequestValidator
    {
        return new SignedAgentRequestValidator(
            $this->keyStore(),
            $verifier,
            new AgentSignatureNonceStore(new ArrayAdapter()),
            new SignedAgentMessageCanonicalizer(),
        );
    }

    private function keyStore(): AgentPublicKeyStore
    {
        return new AgentPublicKeyStore(new ArrayAdapter());
    }

    private function signedRequest(string $nonce = 'nonce-1'): Request
    {
        $request = Request::create('/api/v1/jobs/job-1/claim', 'POST', [], [], [], [], '{}');
        $request->headers->set('X-Retaia-Agent-Id', self::agentId());
        $request->headers->set('X-Retaia-OpenPGP-Fingerprint', self::fingerprint());
        $request->headers->set('X-Retaia-Signature', base64_encode('signature'));
        $request->headers->set('X-Retaia-Signature-Timestamp', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM));
        $request->headers->set('X-Retaia-Signature-Nonce', $nonce);

        return $request;
    }

    private static function agentId(): string
    {
        return '11111111-1111-4111-8111-111111111111';
    }

    private static function fingerprint(): string
    {
        return 'ABCD1234EF567890ABCD1234EF567890ABCD1234';
    }
}

final class AlwaysValidVerifier implements AgentRequestSignatureVerifier
{
    public function verify(string $publicKey, string $expectedFingerprint, string $message, string $signature): bool
    {
        return $publicKey !== '' && $expectedFingerprint !== '' && $message !== '' && $signature !== '';
    }
}

final class AlwaysInvalidVerifier implements AgentRequestSignatureVerifier
{
    public function verify(string $publicKey, string $expectedFingerprint, string $message, string $signature): bool
    {
        return false;
    }
}
