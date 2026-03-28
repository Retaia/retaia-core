<?php

namespace App\Tests\Unit\Api;

use App\Api\Service\AgentRuntimeStore;
use App\Api\Service\AgentSignature\AgentPublicKeyRecord;
use App\Api\Service\AgentSignature\AgentPublicKeyRepository;
use App\Api\Service\AgentSignature\AgentRequestSignatureVerifier;
use App\Api\Service\AgentSignature\AgentSignatureNonceRepository;
use App\Api\Service\AgentSignature\SignedAgentMessageCanonicalizer;
use App\Api\Service\SignedAgentRequestValidator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
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
        $store = $this->publicKeyRepository();
        $store->save(new AgentPublicKeyRecord(self::agentId(), self::fingerprint(), 'public-key', 1710000000));
        $validator = new SignedAgentRequestValidator(
            $store,
            new AlwaysInvalidVerifier(),
            $this->nonceRepository(),
            new SignedAgentMessageCanonicalizer(),
            $this->runtimeStore(),
        );

        $response = $validator->violationResponse($this->signedRequest());

        self::assertNotNull($response);
        self::assertSame(['X-Retaia-Signature'], json_decode((string) $response->getContent(), true)['details']['invalid_headers'] ?? null);
    }

    public function testRejectsReplayNonce(): void
    {
        $store = $this->publicKeyRepository();
        $store->save(new AgentPublicKeyRecord(self::agentId(), self::fingerprint(), 'public-key', 1710000000));
        $validator = new SignedAgentRequestValidator(
            $store,
            new AlwaysValidVerifier(),
            $this->nonceRepository(),
            new SignedAgentMessageCanonicalizer(),
            $this->runtimeStore(),
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
            $this->publicKeyRepository(),
            $verifier,
            $this->nonceRepository(),
            new SignedAgentMessageCanonicalizer(),
            $this->runtimeStore(),
        );
    }

    private function publicKeyRepository(): AgentPublicKeyRepository
    {
        return new AgentPublicKeyRepository($this->connection());
    }

    private function nonceRepository(): AgentSignatureNonceRepository
    {
        return new AgentSignatureNonceRepository($this->connection());
    }

    private function runtimeStore(): AgentRuntimeStore
    {
        return new AgentRuntimeStore($this->connection());
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement("CREATE TABLE agent_runtime (agent_id VARCHAR(36) PRIMARY KEY NOT NULL, client_id VARCHAR(64) NOT NULL, agent_name VARCHAR(255) NOT NULL, agent_version VARCHAR(64) NOT NULL, os_name VARCHAR(32) DEFAULT NULL, os_version VARCHAR(64) DEFAULT NULL, arch VARCHAR(32) DEFAULT NULL, effective_capabilities CLOB NOT NULL, capability_warnings CLOB NOT NULL, last_register_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, last_heartbeat_at DATETIME DEFAULT NULL, max_parallel_jobs INTEGER NOT NULL, feature_flags_contract_version VARCHAR(32) DEFAULT NULL, effective_feature_flags_contract_version VARCHAR(32) DEFAULT NULL, server_time_skew_seconds INTEGER DEFAULT NULL)");
        $connection->executeStatement('CREATE TABLE agent_public_key (agent_id VARCHAR(36) PRIMARY KEY NOT NULL, openpgp_fingerprint VARCHAR(40) NOT NULL, openpgp_public_key CLOB NOT NULL, updated_at INTEGER NOT NULL)');
        $connection->executeStatement('CREATE TABLE agent_signature_nonce (nonce_key VARCHAR(64) PRIMARY KEY NOT NULL, agent_id VARCHAR(36) NOT NULL, expires_at INTEGER NOT NULL, consumed_at INTEGER NOT NULL)');
        $connection->executeStatement('CREATE INDEX idx_agent_signature_nonce_expires_at ON agent_signature_nonce (expires_at)');

        return $connection;
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
