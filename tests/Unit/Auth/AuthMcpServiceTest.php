<?php

namespace App\Tests\Unit\Auth;

use App\Api\Service\AgentSignature\GpgCliAgentRequestSignatureVerifier;
use App\Auth\AuthClientAdminService;
use App\Auth\AuthClientPolicyService;
use App\Auth\AuthClientRegistryRepository;
use App\Auth\AuthMcpChallengeRepository;
use App\Auth\AuthMcpService;
use App\Auth\ClientAccessTokenFactory;
use App\Auth\TechnicalAccessTokenRepository;
use App\Feature\FeatureGovernanceService;
use App\Tests\Support\AgentSigningTestHelper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class AuthMcpServiceTest extends TestCase
{
    public function testMintTokenAcceptsValidOpenPgpSignature(): void
    {
        $service = $this->service();
        $material = AgentSigningTestHelper::publicMaterial();

        $register = $service->register($material['public_key'], $material['fingerprint'], 'mcp-test');
        self::assertSame('SUCCESS', $register['status']);
        $clientId = (string) (($register['payload']['client_id'] ?? ''));
        self::assertNotSame('', $clientId);

        $challenge = $service->createChallenge($clientId, $material['fingerprint']);
        self::assertSame('SUCCESS', $challenge['status']);
        $challengeId = (string) (($challenge['payload']['challenge_id'] ?? ''));
        $challengeValue = (string) (($challenge['payload']['challenge'] ?? ''));

        $mint = $service->mintToken(
            $clientId,
            $material['fingerprint'],
            $challengeId,
            AgentSigningTestHelper::signMessage($challengeValue)
        );

        self::assertSame('SUCCESS', $mint['status']);
        self::assertSame($clientId, $mint['payload']['client_id'] ?? null);
        self::assertSame('MCP', $mint['payload']['client_kind'] ?? null);
        self::assertIsString($mint['payload']['access_token'] ?? null);
    }

    public function testMintTokenRejectsForgedOpenPgpSignature(): void
    {
        $service = $this->service();
        $material = AgentSigningTestHelper::publicMaterial();

        $register = $service->register($material['public_key'], $material['fingerprint'], 'mcp-test');
        $clientId = (string) (($register['payload']['client_id'] ?? ''));

        $challenge = $service->createChallenge($clientId, $material['fingerprint']);
        $challengeId = (string) (($challenge['payload']['challenge_id'] ?? ''));

        $mint = $service->mintToken(
            $clientId,
            $material['fingerprint'],
            $challengeId,
            base64_encode('forged-signature')
        );

        self::assertSame('UNAUTHORIZED', $mint['status']);
    }

    private function service(): AuthMcpService
    {
        $connection = $this->connection();
        $registry = new AuthClientRegistryRepository($connection);
        $challengeRepository = new AuthMcpChallengeRepository($connection);
        $policyService = new AuthClientPolicyService(new FeatureGovernanceService(new ArrayAdapter(), true, false, false));
        $adminService = new AuthClientAdminService(
            $registry,
            new TechnicalAccessTokenRepository($connection),
            new ClientAccessTokenFactory('test-secret', 3600),
            $policyService,
        );

        return new AuthMcpService(
            $registry,
            $challengeRepository,
            $adminService,
            $policyService,
            new GpgCliAgentRequestSignatureVerifier(),
        );
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection->executeStatement('CREATE TABLE auth_client_registry (client_id VARCHAR(64) PRIMARY KEY NOT NULL, client_kind VARCHAR(32) NOT NULL, secret_key VARCHAR(128) DEFAULT NULL, client_label VARCHAR(255) DEFAULT NULL, openpgp_public_key CLOB DEFAULT NULL, openpgp_fingerprint VARCHAR(40) DEFAULT NULL, registered_at VARCHAR(32) DEFAULT NULL, rotated_at VARCHAR(32) DEFAULT NULL)');
        $connection->executeStatement('CREATE TABLE auth_client_access_token (client_id VARCHAR(64) PRIMARY KEY NOT NULL, access_token CLOB NOT NULL, client_kind VARCHAR(32) NOT NULL, issued_at INTEGER NOT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX uniq_auth_client_access_token_token ON auth_client_access_token (access_token)');
        $connection->executeStatement('CREATE TABLE auth_mcp_challenge (challenge_id VARCHAR(32) PRIMARY KEY NOT NULL, client_id VARCHAR(64) NOT NULL, openpgp_fingerprint VARCHAR(40) NOT NULL, challenge VARCHAR(128) NOT NULL, expires_at INTEGER NOT NULL, used BOOLEAN NOT NULL, used_at INTEGER DEFAULT NULL)');

        return $connection;
    }
}
