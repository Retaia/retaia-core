<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\GetMyFeaturesHandler;
use App\Application\Auth\PatchMyFeaturesHandler;
use App\Application\Auth\PatchMyFeaturesResult;
use App\Application\Auth\Port\FeatureGovernanceGateway;
use PHPUnit\Framework\TestCase;

final class PatchMyFeaturesHandlerTest extends TestCase
{
    public function testHandleReturnsForbiddenScopeForCoreFeatures(): void
    {
        $gateway = $this->createMock(FeatureGovernanceGateway::class);
        $gateway->expects(self::once())->method('coreV1GlobalFeatures')->willReturn(['features.core.auth']);
        $gateway->expects(self::never())->method('setUserFeatureEnabled');

        $handler = new PatchMyFeaturesHandler($gateway, new GetMyFeaturesHandler($gateway));
        $result = $handler->handle('u-1', ['features.core.auth' => false]);

        self::assertSame(PatchMyFeaturesResult::STATUS_FORBIDDEN_SCOPE, $result->status());
    }

    public function testHandleReturnsValidationFailedWhenPayloadInvalid(): void
    {
        $gateway = $this->createMock(FeatureGovernanceGateway::class);
        $gateway->expects(self::once())->method('coreV1GlobalFeatures')->willReturn(['features.core.auth']);
        $gateway->expects(self::once())->method('allowedUserFeatureKeys')->willReturn(['features.ai.suggest_tags']);
        $gateway->expects(self::once())->method('validateFeaturePayload')->willReturn([
            'unknown_keys' => ['features.unknown'],
            'non_boolean_keys' => [],
        ]);
        $gateway->expects(self::never())->method('setUserFeatureEnabled');

        $handler = new PatchMyFeaturesHandler($gateway, new GetMyFeaturesHandler($gateway));
        $result = $handler->handle('u-1', ['features.unknown' => true]);

        self::assertSame(PatchMyFeaturesResult::STATUS_VALIDATION_FAILED, $result->status());
        self::assertSame(['unknown_keys' => ['features.unknown'], 'non_boolean_keys' => []], $result->validationDetails());
    }

    public function testHandleReturnsUpdatedWhenPayloadValid(): void
    {
        $gateway = $this->createMock(FeatureGovernanceGateway::class);
        $gateway->expects(self::exactly(2))->method('coreV1GlobalFeatures')->willReturn(['features.core.auth']);
        $gateway->expects(self::once())->method('allowedUserFeatureKeys')->willReturn(['features.ai.suggest_tags']);
        $gateway->expects(self::once())->method('validateFeaturePayload')->willReturn([
            'unknown_keys' => [],
            'non_boolean_keys' => [],
        ]);
        $gateway->expects(self::once())->method('setUserFeatureEnabled')->with('u-1', ['features.ai.suggest_tags' => false]);
        $gateway->expects(self::once())->method('userFeatureEnabled')->with('u-1')->willReturn(['features.ai.suggest_tags' => false]);
        $gateway->expects(self::once())->method('effectiveFeatureEnabledForUser')->with('u-1')->willReturn(['features.ai.suggest_tags' => false]);
        $gateway->expects(self::once())->method('featureGovernanceRules')->willReturn([['key' => 'features.ai.suggest_tags']]);

        $handler = new PatchMyFeaturesHandler($gateway, new GetMyFeaturesHandler($gateway));
        $result = $handler->handle('u-1', ['features.ai.suggest_tags' => false]);

        self::assertSame(PatchMyFeaturesResult::STATUS_UPDATED, $result->status());
        self::assertSame(['features.ai.suggest_tags' => false], $result->features()?->userFeatureEnabled() ?? []);
    }
}
