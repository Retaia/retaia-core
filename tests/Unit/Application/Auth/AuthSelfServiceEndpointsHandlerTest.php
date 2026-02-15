<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\AuthMeEndpointResult;
use App\Application\Auth\AuthSelfServiceEndpointsHandler;
use App\Application\Auth\DisableTwoFactorHandler;
use App\Application\Auth\EnableTwoFactorHandler;
use App\Application\Auth\GetAuthMeProfileHandler;
use App\Application\Auth\GetMyFeaturesEndpointResult;
use App\Application\Auth\GetMyFeaturesHandler;
use App\Application\Auth\PatchMyFeaturesEndpointResult;
use App\Application\Auth\PatchMyFeaturesHandler;
use App\Application\Auth\RegenerateTwoFactorRecoveryCodesHandler;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\TwoFactorEnableEndpointResult;
use App\Application\Auth\TwoFactorRecoveryCodesEndpointResult;
use App\Application\Auth\TwoFactorSetupEndpointResult;
use App\Application\Auth\SetupTwoFactorHandler;
use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\Port\FeatureGovernanceGateway;
use App\Application\Auth\Port\TwoFactorGateway;
use PHPUnit\Framework\TestCase;

final class AuthSelfServiceEndpointsHandlerTest extends TestCase
{
    public function testMeReturnsUnauthorizedWhenNoAuthenticatedUser(): void
    {
        $handler = $this->buildHandler(null);

        $result = $handler->me();

        self::assertSame(AuthMeEndpointResult::STATUS_UNAUTHORIZED, $result->status());
    }

    public function testMeReturnsProfileWhenAuthenticated(): void
    {
        $handler = $this->buildHandler([
            'id' => 'u_1',
            'email' => 'admin@retaia.local',
            'roles' => ['ROLE_ADMIN'],
        ]);

        $result = $handler->me();

        self::assertSame(AuthMeEndpointResult::STATUS_SUCCESS, $result->status());
        self::assertSame('u_1', $result->id());
        self::assertSame('admin@retaia.local', $result->email());
        self::assertSame(['ROLE_ADMIN'], $result->roles());
    }

    public function testTwoFactorSetupReturnsAlreadyEnabled(): void
    {
        $twoFactorGateway = $this->createMock(TwoFactorGateway::class);
        $twoFactorGateway->expects(self::once())
            ->method('setup')
            ->with('u_2', 'mfa@retaia.local')
            ->willThrowException(new \RuntimeException('MFA_ALREADY_ENABLED'));

        $handler = $this->buildHandler(
            ['id' => 'u_2', 'email' => 'mfa@retaia.local', 'roles' => ['ROLE_USER']],
            $twoFactorGateway
        );

        $result = $handler->twoFactorSetup();

        self::assertSame(TwoFactorSetupEndpointResult::STATUS_ALREADY_ENABLED, $result->status());
    }

    public function testTwoFactorEnableReturnsValidationFailedWhenOtpMissing(): void
    {
        $twoFactorGateway = $this->createMock(TwoFactorGateway::class);
        $twoFactorGateway->expects(self::never())->method('enable');

        $handler = $this->buildHandler(
            ['id' => 'u_3', 'email' => 'user@retaia.local', 'roles' => ['ROLE_USER']],
            $twoFactorGateway
        );

        $result = $handler->twoFactorEnable([]);

        self::assertSame(TwoFactorEnableEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testTwoFactorEnableReturnsInvalidCodeWhenGatewayRejectsCode(): void
    {
        $twoFactorGateway = $this->createMock(TwoFactorGateway::class);
        $twoFactorGateway->expects(self::once())
            ->method('enable')
            ->with('u_4', '000000')
            ->willReturn(false);

        $handler = $this->buildHandler(
            ['id' => 'u_4', 'email' => 'user@retaia.local', 'roles' => ['ROLE_USER']],
            $twoFactorGateway
        );

        $result = $handler->twoFactorEnable(['otp_code' => '000000']);

        self::assertSame(TwoFactorEnableEndpointResult::STATUS_INVALID_CODE, $result->status());
    }

    public function testRegenerateRecoveryCodesReturnsUnauthorizedWhenNotAuthenticated(): void
    {
        $handler = $this->buildHandler(null);

        $result = $handler->regenerateTwoFactorRecoveryCodes();

        self::assertSame(TwoFactorRecoveryCodesEndpointResult::STATUS_UNAUTHORIZED, $result->status());
    }

    public function testRegenerateRecoveryCodesReturnsNotEnabledWhenGatewayThrows(): void
    {
        $twoFactorGateway = $this->createMock(TwoFactorGateway::class);
        $twoFactorGateway->expects(self::once())
            ->method('regenerateRecoveryCodes')
            ->with('u_7')
            ->willThrowException(new \RuntimeException('MFA_NOT_ENABLED'));

        $handler = $this->buildHandler(
            ['id' => 'u_7', 'email' => 'user@retaia.local', 'roles' => ['ROLE_USER']],
            $twoFactorGateway
        );

        $result = $handler->regenerateTwoFactorRecoveryCodes();

        self::assertSame(TwoFactorRecoveryCodesEndpointResult::STATUS_NOT_ENABLED, $result->status());
    }

    public function testGetMyFeaturesReturnsUnauthorizedWhenNotAuthenticated(): void
    {
        $handler = $this->buildHandler(null);

        $result = $handler->getMyFeatures();

        self::assertSame(GetMyFeaturesEndpointResult::STATUS_UNAUTHORIZED, $result->status());
    }

    public function testPatchMyFeaturesReturnsValidationFailedWhenPayloadIsInvalid(): void
    {
        $handler = $this->buildHandler([
            'id' => 'u_5',
            'email' => 'features@retaia.local',
            'roles' => ['ROLE_USER'],
        ]);

        $result = $handler->patchMyFeatures([]);

        self::assertSame(PatchMyFeaturesEndpointResult::STATUS_VALIDATION_FAILED_PAYLOAD, $result->status());
    }

    public function testPatchMyFeaturesReturnsValidationDetailsWhenDomainValidationFails(): void
    {
        $featureGateway = $this->createMock(FeatureGovernanceGateway::class);
        $featureGateway->expects(self::once())->method('coreV1GlobalFeatures')->willReturn([]);
        $featureGateway->expects(self::once())->method('allowedUserFeatureKeys')->willReturn(['features.ai']);
        $featureGateway->expects(self::once())
            ->method('validateFeaturePayload')
            ->with(['features.unknown' => true], ['features.ai'])
            ->willReturn([
                'unknown_keys' => ['features.unknown'],
                'non_boolean_keys' => [],
            ]);
        $featureGateway->expects(self::never())->method('setUserFeatureEnabled');

        $handler = $this->buildHandler(
            ['id' => 'u_6', 'email' => 'features@retaia.local', 'roles' => ['ROLE_USER']],
            null,
            $featureGateway
        );

        $result = $handler->patchMyFeatures(['user_feature_enabled' => ['features.unknown' => true]]);

        self::assertSame(PatchMyFeaturesEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
        self::assertSame(['unknown_keys' => ['features.unknown'], 'non_boolean_keys' => []], $result->validationDetails());
    }

    /**
     * @param array{id: string, email: string, roles: array<int, string>}|null $currentUser
     */
    private function buildHandler(?array $currentUser, ?TwoFactorGateway $twoFactorGateway = null, ?FeatureGovernanceGateway $featureGateway = null): AuthSelfServiceEndpointsHandler
    {
        $authenticatedUserGateway = $this->createMock(AuthenticatedUserGateway::class);
        $authenticatedUserGateway->method('currentUser')->willReturn($currentUser);

        $twoFactorGateway ??= $this->createMock(TwoFactorGateway::class);
        $featureGateway ??= $this->createMock(FeatureGovernanceGateway::class);

        return new AuthSelfServiceEndpointsHandler(
            new ResolveAuthenticatedUserHandler($authenticatedUserGateway),
            new GetAuthMeProfileHandler(),
            new SetupTwoFactorHandler($twoFactorGateway),
            new EnableTwoFactorHandler($twoFactorGateway),
            new DisableTwoFactorHandler($twoFactorGateway),
            new RegenerateTwoFactorRecoveryCodesHandler($twoFactorGateway),
            new GetMyFeaturesHandler($featureGateway),
            new PatchMyFeaturesHandler($featureGateway, new GetMyFeaturesHandler($featureGateway)),
        );
    }
}
