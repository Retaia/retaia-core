<?php

namespace App\Tests\Unit\Auth;

use App\Auth\AuthClientPolicyService;
use App\Auth\AuthClientRegistryEntry;
use App\Auth\AuthClientRegistryRepositoryInterface;
use App\Auth\AuthMcpClientRegistrationService;
use App\Auth\AuthMcpClientRegistryService;
use App\Auth\AuthMcpRegistrationNormalizer;
use App\Feature\FeatureExplanationBuilder;
use App\Feature\FeatureGovernanceRulesProvider;
use App\Feature\FeatureGovernanceService;
use App\Feature\FeaturePayloadValidator;
use App\Feature\FeatureToggleStore;
use App\Domain\AuthClient\ClientKind;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class AuthMcpClientRegistrationServiceTest extends TestCase
{
    private const FINGERPRINT = 'AABBCCDDEEFF00112233445566778899AABBCCDD';

    public function testRegisterReturnsForbiddenWhenMcpIsDisabled(): void
    {
        $policy = $this->policyService(false);

        $service = new AuthMcpClientRegistrationService(
            $policy,
            new AuthMcpRegistrationNormalizer(),
            new AuthMcpClientRegistryService($this->createMock(AuthClientRegistryRepositoryInterface::class)),
        );

        self::assertSame(['status' => 'FORBIDDEN_SCOPE'], $service->register('key', self::FINGERPRINT));
    }

    public function testRotateKeyNormalizesInputsAndDelegatesToRegistry(): void
    {
        $policy = $this->policyService(true);
        $registryRepository = $this->createMock(AuthClientRegistryRepositoryInterface::class);
        $registryRepository->method('findByClientId')->with('client-1')->willReturn(new AuthClientRegistryEntry('client-1', \App\Domain\AuthClient\ClientKind::MCP, null, 'Label', 'OLD KEY', '00112233445566778899AABBCCDDEEFF00112233', '2026', null));
        $registryRepository->method('findAll')->willReturn([]);
        $registryRepository->expects(self::once())->method('save')->with(self::isInstanceOf(AuthClientRegistryEntry::class));

        $service = new AuthMcpClientRegistrationService(
            $policy,
            new AuthMcpRegistrationNormalizer(),
            new AuthMcpClientRegistryService($registryRepository),
        );

        self::assertSame('SUCCESS', $service->rotateKey('client-1', 'PUBLIC KEY', self::FINGERPRINT, 'Label')['status']);
        self::assertSame(self::FINGERPRINT, $service->normalizeFingerprint('AA BB CC DD EE FF 00 11 22 33 44 55 66 77 88 99 AA BB CC DD'));
    }

    private function policyService(bool $mcpEnabled): AuthClientPolicyService
    {
        $toggleStore = new FeatureToggleStore(new ArrayAdapter());
        $toggleStore->setAppFeatureEnabled(['features.ai' => $mcpEnabled]);

        return new AuthClientPolicyService(new FeatureGovernanceService(
            new FeatureGovernanceRulesProvider(true, false, true),
            new FeaturePayloadValidator(),
            $toggleStore,
            new FeatureExplanationBuilder(),
        ));
    }
}
