<?php

namespace App\Tests\Unit\Application\AppPolicy;

use App\Application\AppPolicy\GetAppFeaturesHandler;
use App\Application\AppPolicy\PatchAppFeaturesHandler;
use App\Application\AppPolicy\PatchAppFeaturesResult;
use App\Application\AppPolicy\Port\AppFeatureGovernanceGateway;
use PHPUnit\Framework\TestCase;

final class PatchAppFeaturesHandlerTest extends TestCase
{
    public function testHandleReturnsValidationFailedWhenPayloadInvalid(): void
    {
        $gateway = $this->createMock(AppFeatureGovernanceGateway::class);
        $gateway->expects(self::once())->method('allowedAppFeatureKeys')->willReturn(['features.ai']);
        $gateway->expects(self::once())->method('validateFeaturePayload')->willReturn([
            'unknown_keys' => ['features.unknown.flag'],
            'non_boolean_keys' => [],
        ]);
        $gateway->expects(self::never())->method('setAppFeatureEnabled');

        $handler = new PatchAppFeaturesHandler($gateway, new GetAppFeaturesHandler($gateway));
        $result = $handler->handle(['features.unknown.flag' => true]);

        self::assertSame(PatchAppFeaturesResult::STATUS_VALIDATION_FAILED, $result->status());
        self::assertSame(['unknown_keys' => ['features.unknown.flag'], 'non_boolean_keys' => []], $result->validationDetails());
    }

    public function testHandleReturnsUpdatedWhenPayloadValid(): void
    {
        $gateway = $this->createMock(AppFeatureGovernanceGateway::class);
        $gateway->expects(self::once())->method('allowedAppFeatureKeys')->willReturn(['features.ai']);
        $gateway->expects(self::once())->method('validateFeaturePayload')->willReturn([
            'unknown_keys' => [],
            'non_boolean_keys' => [],
        ]);
        $gateway->expects(self::once())->method('setAppFeatureEnabled')->with(['features.ai' => false]);
        $gateway->expects(self::once())->method('appFeatureEnabled')->willReturn(['features.ai' => false]);
        $gateway->expects(self::once())->method('featureGovernanceRules')->willReturn([['key' => 'features.ai']]);
        $gateway->expects(self::once())->method('coreV1GlobalFeatures')->willReturn(['features.core.auth']);

        $handler = new PatchAppFeaturesHandler($gateway, new GetAppFeaturesHandler($gateway));
        $result = $handler->handle(['features.ai' => false]);

        self::assertSame(PatchAppFeaturesResult::STATUS_UPDATED, $result->status());
        self::assertSame(['features.ai' => false], $result->features()?->appFeatureEnabled() ?? []);
    }
}
