<?php

namespace App\Tests\Unit\Auth;

use App\Auth\AuthClientAdminService;
use App\Auth\AuthClientPolicyService;
use App\Auth\AuthClientRegistryEntry;
use App\Auth\AuthClientRegistryRepositoryInterface;
use App\Auth\AuthClientSecretRotationService;
use App\Auth\AuthClientTokenMintingService;
use App\Auth\ClientAccessTokenFactory;
use App\Auth\TechnicalAccessTokenRecord;
use App\Auth\TechnicalAccessTokenRepositoryInterface;
use App\Feature\FeatureExplanationBuilder;
use App\Feature\FeatureGovernanceRulesProvider;
use App\Feature\FeatureGovernanceService;
use App\Feature\FeaturePayloadValidator;
use App\Feature\FeatureToggleStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class AuthClientAdminServiceTest extends TestCase
{
    public function testFacadeDelegatesAndReadsRegistryInformation(): void
    {
        $registry = $this->createMock(AuthClientRegistryRepositoryInterface::class);
        $tokens = $this->createMock(TechnicalAccessTokenRepositoryInterface::class);

        $entry = new AuthClientRegistryEntry('client-1', 'mcp', null, null, null, null, null, null);
        $registry->method('findByClientId')->willReturn($entry);
        $tokens->expects(self::atLeastOnce())->method('save')->with(self::isInstanceOf(TechnicalAccessTokenRecord::class));
        $tokens->expects(self::atLeastOnce())->method('deleteByClientId')->with('client-1');

        $minting = new AuthClientTokenMintingService($registry, $tokens, new ClientAccessTokenFactory('secret', 3600));
        $rotation = new AuthClientSecretRotationService($registry, $tokens);
        $policy = new AuthClientPolicyService(new FeatureGovernanceService(
            new FeatureGovernanceRulesProvider(true, false, true),
            new FeaturePayloadValidator(),
            new FeatureToggleStore(new ArrayAdapter()),
            new FeatureExplanationBuilder(),
        ));

        $service = new AuthClientAdminService($registry, $minting, $rotation, $policy);

        self::assertTrue($service->hasClient('client-1'));
        self::assertSame('mcp', $service->clientKind('client-1'));
        self::assertNotNull($service->rotateSecret('client-1'));
        self::assertTrue($service->revokeToken('client-1'));
        self::assertFalse($service->isMcpDisabledByAppPolicy());
        self::assertNull($service->mintToken('client-1', 'mcp', 'secret-1'));
        self::assertNotNull($service->mintRegisteredClientToken('client-1')['access_token'] ?? null);
    }
}
