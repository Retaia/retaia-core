<?php

namespace App\Tests\Unit\Application\AppPolicy;

use App\Application\AppPolicy\AppPolicyEndpointResult;
use App\Application\AppPolicy\AppPolicyEndpointsHandler;
use App\Application\AppPolicy\GetAppFeaturesEndpointResult;
use App\Application\AppPolicy\GetAppFeaturesHandler;
use App\Application\AppPolicy\GetAppPolicyHandler;
use App\Application\AppPolicy\PatchAppFeaturesEndpointResult;
use App\Application\AppPolicy\PatchAppFeaturesHandler;
use App\Application\AppPolicy\Port\AppFeatureGovernanceGateway;
use App\Application\Auth\ResolveAdminActorHandler;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\Port\AdminActorGateway;
use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Domain\AppPolicy\FeatureFlagsContractPolicy;
use PHPUnit\Framework\TestCase;

final class AppPolicyEndpointsHandlerTest extends TestCase
{
    public function testPolicyReturnsUnsupportedWhenClientVersionIsUnknown(): void
    {
        $handler = $this->buildHandler(
            null,
            false,
            new GetAppPolicyHandler(
                new FeatureFlagsContractPolicy(),
                true,
                false,
                true,
                '1.0.0',
                ['0.9.0']
            )
        );

        $result = $handler->policy('2.0.0');

        self::assertSame(AppPolicyEndpointResult::STATUS_UNSUPPORTED_CONTRACT_VERSION, $result->status());
        self::assertSame(['0.9.0', '1.0.0'], $result->acceptedVersions());
    }

    public function testFeaturesReturnsUnauthorizedWithoutAuthenticatedUser(): void
    {
        $handler = $this->buildHandler(null, false);

        $result = $handler->features();

        self::assertSame(GetAppFeaturesEndpointResult::STATUS_UNAUTHORIZED, $result->status());
    }

    public function testFeaturesReturnsForbiddenForNonAdminActor(): void
    {
        $handler = $this->buildHandler(
            ['id' => 'u_1', 'email' => 'user@retaia.local', 'roles' => ['ROLE_USER']],
            false
        );

        $result = $handler->features();

        self::assertSame(GetAppFeaturesEndpointResult::STATUS_FORBIDDEN_ACTOR, $result->status());
    }

    public function testPatchFeaturesReturnsValidationFailedWhenPayloadIsInvalid(): void
    {
        $handler = $this->buildHandler(
            ['id' => 'u_2', 'email' => 'admin@retaia.local', 'roles' => ['ROLE_ADMIN']],
            true
        );

        $result = $handler->patchFeatures([]);

        self::assertSame(PatchAppFeaturesEndpointResult::STATUS_VALIDATION_FAILED_PAYLOAD, $result->status());
    }

    public function testPatchFeaturesReturnsValidationDetailsWhenDomainValidationFails(): void
    {
        $gateway = $this->createMock(AppFeatureGovernanceGateway::class);
        $gateway->expects(self::once())->method('allowedAppFeatureKeys')->willReturn(['features.ai']);
        $gateway->expects(self::once())
            ->method('validateFeaturePayload')
            ->with(['features.unknown.flag' => true], ['features.ai'])
            ->willReturn([
                'unknown_keys' => ['features.unknown.flag'],
                'non_boolean_keys' => [],
            ]);
        $gateway->expects(self::never())->method('setAppFeatureEnabled');

        $handler = $this->buildHandler(
            ['id' => 'u_3', 'email' => 'admin@retaia.local', 'roles' => ['ROLE_ADMIN']],
            true,
            null,
            $gateway
        );

        $result = $handler->patchFeatures(['app_feature_enabled' => ['features.unknown.flag' => true]]);

        self::assertSame(PatchAppFeaturesEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
        self::assertSame(['unknown_keys' => ['features.unknown.flag'], 'non_boolean_keys' => []], $result->validationDetails());
    }

    /**
     * @param array{id: string, email: string, roles: array<int, string>}|null $currentUser
     */
    private function buildHandler(
        ?array $currentUser,
        bool $isAdmin,
        ?GetAppPolicyHandler $getAppPolicyHandler = null,
        ?AppFeatureGovernanceGateway $appFeatureGateway = null,
    ): AppPolicyEndpointsHandler {
        $authenticatedUserGateway = $this->createMock(AuthenticatedUserGateway::class);
        $authenticatedUserGateway->method('currentUser')->willReturn($currentUser);

        $adminActorGateway = $this->createMock(AdminActorGateway::class);
        $adminActorGateway->method('isAdmin')->willReturn($isAdmin);
        $adminActorGateway->method('actorId')->willReturn($isAdmin ? 'admin_1' : null);

        $appFeatureGateway ??= $this->createMock(AppFeatureGovernanceGateway::class);
        $appFeatureGateway->method('appFeatureEnabled')->willReturn(['features.ai' => true]);
        $appFeatureGateway->method('featureGovernanceRules')->willReturn([['key' => 'features.ai']]);
        $appFeatureGateway->method('coreV1GlobalFeatures')->willReturn(['features.core.auth']);
        $appFeatureGateway->method('allowedAppFeatureKeys')->willReturn(['features.ai']);
        $appFeatureGateway->method('validateFeaturePayload')->willReturn(['unknown_keys' => [], 'non_boolean_keys' => []]);

        $getAppPolicyHandler ??= new GetAppPolicyHandler(
            new FeatureFlagsContractPolicy(),
            true,
            false,
            true,
            '1.0.0',
            ['0.9.0']
        );

        return new AppPolicyEndpointsHandler(
            $getAppPolicyHandler,
            new GetAppFeaturesHandler($appFeatureGateway),
            new PatchAppFeaturesHandler($appFeatureGateway, new GetAppFeaturesHandler($appFeatureGateway)),
            new ResolveAuthenticatedUserHandler($authenticatedUserGateway),
            new ResolveAdminActorHandler($adminActorGateway),
        );
    }
}
