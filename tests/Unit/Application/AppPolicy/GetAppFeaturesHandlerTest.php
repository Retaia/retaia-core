<?php

namespace App\Tests\Unit\Application\AppPolicy;

use App\Application\AppPolicy\GetAppFeaturesHandler;
use App\Application\AppPolicy\Port\AppFeatureGovernanceGateway;
use PHPUnit\Framework\TestCase;

final class GetAppFeaturesHandlerTest extends TestCase
{
    public function testHandleReturnsAppFeaturesPayload(): void
    {
        $gateway = $this->createMock(AppFeatureGovernanceGateway::class);
        $gateway->expects(self::once())->method('appFeatureEnabled')->willReturn(['features.ai' => true]);
        $gateway->expects(self::once())->method('featureGovernanceRules')->willReturn([['key' => 'features.ai']]);
        $gateway->expects(self::once())->method('coreV1GlobalFeatures')->willReturn(['features.core.auth']);

        $handler = new GetAppFeaturesHandler($gateway);
        $result = $handler->handle();

        self::assertSame(['features.ai' => true], $result->appFeatureEnabled());
        self::assertSame([['key' => 'features.ai']], $result->featureGovernance());
        self::assertSame(['features.core.auth'], $result->coreV1GlobalFeatures());
    }
}
