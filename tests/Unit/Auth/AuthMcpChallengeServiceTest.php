<?php

namespace App\Tests\Unit\Auth;

use App\Api\Service\AgentSignature\AgentRequestSignatureVerifier;
use App\Auth\AuthClientAdminService;
use App\Auth\AuthClientPolicyService;
use App\Auth\AuthClientRegistryEntry;
use App\Auth\AuthClientRegistryRepositoryInterface;
use App\Auth\AuthClientSecretRotationService;
use App\Auth\AuthClientTokenMintingService;
use App\Auth\AuthMcpChallenge;
use App\Auth\AuthMcpChallengeRepositoryInterface;
use App\Auth\AuthMcpChallengeService;
use App\Auth\AuthMcpClientRegistrationService;
use App\Auth\AuthMcpClientRegistryService;
use App\Auth\AuthMcpRegistrationNormalizer;
use App\Auth\ClientAccessTokenFactory;
use App\Auth\TechnicalAccessTokenRepositoryInterface;
use App\Feature\FeatureExplanationBuilder;
use App\Feature\FeatureGovernanceRulesProvider;
use App\Feature\FeatureGovernanceService;
use App\Feature\FeaturePayloadValidator;
use App\Feature\FeatureToggleStore;
use App\Domain\AuthClient\ClientKind;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class AuthMcpChallengeServiceTest extends TestCase
{
    private const FINGERPRINT = 'AABBCCDDEEFF00112233445566778899AABBCCDD';

    public function testCreateChallengeValidatesAndPersistsChallenge(): void
    {
        $repository = $this->createMock(AuthMcpChallengeRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(function (AuthMcpChallenge $challenge): bool {
                self::assertSame('client-1', $challenge->clientId);
                self::assertSame(self::FINGERPRINT, $challenge->openPgpFingerprint);
                self::assertFalse($challenge->used);

                return str_starts_with($challenge->challengeId, 'mcpc_') && $challenge->challenge !== '';
            }));

        [$admin, $policy, $registration] = $this->authServices();
        $signatureVerifier = $this->createMock(AgentRequestSignatureVerifier::class);

        $service = new AuthMcpChallengeService($repository, $admin, $policy, $signatureVerifier, $registration);
        $result = $service->createChallenge('client-1', self::FINGERPRINT);

        self::assertSame('SUCCESS', $result['status']);
        self::assertSame(300, $result['payload']['expires_in'] ?? null);
    }

    public function testMintTokenRejectsInvalidSignature(): void
    {
        $challenge = new AuthMcpChallenge('challenge-1', 'client-1', self::FINGERPRINT, 'payload', 1893456300, false, null);

        $repository = $this->createMock(AuthMcpChallengeRepositoryInterface::class);
        $repository->method('findByChallengeId')->with('challenge-1')->willReturn($challenge);

        [$admin, $policy, $registration] = $this->authServices();
        $signatureVerifier = $this->createMock(AgentRequestSignatureVerifier::class);
        $signatureVerifier->method('verify')->willReturn(false);

        $service = new AuthMcpChallengeService($repository, $admin, $policy, $signatureVerifier, $registration);

        self::assertSame(['status' => 'UNAUTHORIZED'], $service->mintToken('client-1', self::FINGERPRINT, 'challenge-1', 'sig'));
    }

    /**
     * @return array{0:AuthClientAdminService,1:AuthClientPolicyService,2:AuthMcpClientRegistrationService}
     */
    private function authServices(): array
    {
        $registryRepository = $this->createMock(AuthClientRegistryRepositoryInterface::class);
        $registryRepository->method('findByClientId')->willReturn(new AuthClientRegistryEntry('client-1', \App\Domain\AuthClient\ClientKind::MCP, 'secret-1', null, 'PUBLIC KEY', self::FINGERPRINT, null, null));
        $registryRepository->method('findAll')->willReturn([]);
        $tokens = $this->createMock(TechnicalAccessTokenRepositoryInterface::class);
        $policy = new AuthClientPolicyService(new FeatureGovernanceService(
            new FeatureGovernanceRulesProvider(true, false, true),
            new FeaturePayloadValidator(),
            new FeatureToggleStore(new ArrayAdapter()),
            new FeatureExplanationBuilder(),
        ));
        $registration = new AuthMcpClientRegistrationService($policy, new AuthMcpRegistrationNormalizer(), new AuthMcpClientRegistryService($registryRepository));
        $admin = new AuthClientAdminService(
            $registryRepository,
            new AuthClientTokenMintingService($registryRepository, $tokens, new ClientAccessTokenFactory('secret', 3600)),
            new AuthClientSecretRotationService($registryRepository, $tokens),
            $policy,
        );

        return [$admin, $policy, $registration];
    }
}
