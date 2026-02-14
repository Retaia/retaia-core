<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\GetMyFeaturesHandler;
use App\Application\Auth\Port\FeatureGovernanceGateway;
use PHPUnit\Framework\TestCase;

final class GetMyFeaturesHandlerTest extends TestCase
{
    public function testHandleReturnsAggregatedFeaturePayload(): void
    {
        $gateway = $this->createMock(FeatureGovernanceGateway::class);
        $gateway->expects(self::once())->method('userFeatureEnabled')->with('u-1')->willReturn(['features.ai.suggest_tags' => true]);
        $gateway->expects(self::once())->method('effectiveFeatureEnabledForUser')->with('u-1')->willReturn(['features.ai.suggest_tags' => true]);
        $gateway->expects(self::once())->method('featureGovernanceRules')->willReturn([['key' => 'features.ai.suggest_tags']]);
        $gateway->expects(self::once())->method('coreV1GlobalFeatures')->willReturn(['features.core.auth']);

        $handler = new GetMyFeaturesHandler($gateway);
        $result = $handler->handle('u-1');

        self::assertSame(['features.ai.suggest_tags' => true], $result->userFeatureEnabled());
        self::assertSame(['features.ai.suggest_tags' => true], $result->effectiveFeatureEnabled());
        self::assertSame([['key' => 'features.ai.suggest_tags']], $result->featureGovernance());
        self::assertSame(['features.core.auth'], $result->coreV1GlobalFeatures());
    }
}
