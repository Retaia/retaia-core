<?php

namespace App\Tests\Unit\Application\AppPolicy;

use App\Application\AppPolicy\GetAppPolicyHandler;
use App\Domain\AppPolicy\FeatureFlagsContractPolicy;
use PHPUnit\Framework\TestCase;

final class GetAppPolicyHandlerTest extends TestCase
{
    public function testHandleReturnsStrictPolicyWhenClientVersionIsOmitted(): void
    {
        $handler = new GetAppPolicyHandler(
            new FeatureFlagsContractPolicy(),
            true,
            false,
            true,
            '1.0.0',
            ['1.0.0', '0.9.0']
        );

        $result = $handler->handle('');

        self::assertTrue($result->isSupported());
        self::assertSame(['1.0.0', '0.9.0'], $result->acceptedVersions());
        self::assertSame('1.0.0', $result->latestVersion());
        self::assertSame('1.0.0', $result->effectiveVersion());
        self::assertSame('STRICT', $result->compatibilityMode());
        self::assertSame(
            [
                'features.ai.suggest_tags' => true,
                'features.ai.suggested_tags_filters' => false,
                'features.decisions.bulk' => true,
            ],
            $result->featureFlags()
        );
    }

    public function testHandleReturnsCompatForAcceptedLegacyVersion(): void
    {
        $handler = new GetAppPolicyHandler(
            new FeatureFlagsContractPolicy(),
            false,
            false,
            false,
            '1.0.0',
            ['0.9.0']
        );

        $result = $handler->handle('0.9.0');

        self::assertTrue($result->isSupported());
        self::assertSame('0.9.0', $result->effectiveVersion());
        self::assertSame('COMPAT', $result->compatibilityMode());
        self::assertSame(['0.9.0', '1.0.0'], $result->acceptedVersions());
    }

    public function testHandleReturnsUnsupportedForUnknownVersion(): void
    {
        $handler = new GetAppPolicyHandler(
            new FeatureFlagsContractPolicy(),
            false,
            false,
            false,
            '1.0.0',
            ['0.9.0']
        );

        $result = $handler->handle('2.0.0');

        self::assertFalse($result->isSupported());
    }
}
