<?php

namespace App\Tests\Unit\Auth;

use App\Api\Service\AgentSignature\GpgCliAgentRequestSignatureVerifier;
use App\Auth\AuthClientAdminService;
use App\Auth\AuthClientPolicyService;
use App\Auth\AuthClientRegistryRepository;
use App\Auth\AuthClientSecretRotationService;
use App\Auth\AuthClientTokenMintingService;
use App\Auth\AuthMcpClientRegistryService;
use App\Auth\AuthMcpRegistrationNormalizer;
use App\Auth\AuthMcpChallengeRepository;
use App\Auth\AuthMcpService;
use App\Auth\ClientAccessTokenFactory;
use App\Auth\TechnicalAccessTokenRepository;
use App\Feature\FeatureGovernanceService;
use App\Tests\Support\AgentSigningTestHelper;
use App\Tests\Support\AuthClientRegistryEntityManagerTrait;
use App\Tests\Support\AuthMcpChallengeEntityManagerTrait;
use App\Tests\Support\TechnicalAccessTokenEntityManagerTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class AuthMcpServiceTest extends TestCase
{
    use AuthClientRegistryEntityManagerTrait;
    use AuthMcpChallengeEntityManagerTrait;
    use TechnicalAccessTokenEntityManagerTrait;

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
        $registry = new AuthClientRegistryRepository($this->authClientRegistryEntityManager());
        $challengeRepository = new AuthMcpChallengeRepository($this->authMcpChallengeEntityManager());
        $policyService = new AuthClientPolicyService(new FeatureGovernanceService(new ArrayAdapter(), true, false, false));
        $tokenRepository = new TechnicalAccessTokenRepository($this->technicalAccessTokenEntityManager());
        $adminService = new AuthClientAdminService(
            $registry,
            new AuthClientTokenMintingService($registry, $tokenRepository, new ClientAccessTokenFactory('test-secret', 3600)),
            new AuthClientSecretRotationService($registry, $tokenRepository),
            $policyService,
        );
        $registrationService = new \App\Auth\AuthMcpClientRegistrationService(
            $policyService,
            new AuthMcpRegistrationNormalizer(),
            new AuthMcpClientRegistryService($registry),
        );
        $challengeService = new \App\Auth\AuthMcpChallengeService(
            $challengeRepository,
            $adminService,
            $policyService,
            new GpgCliAgentRequestSignatureVerifier(),
            $registrationService,
        );

        return new AuthMcpService(
            $registrationService,
            $challengeService,
        );
    }

}
