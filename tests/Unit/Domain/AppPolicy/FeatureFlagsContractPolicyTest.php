<?php

namespace App\Tests\Unit\Domain\AppPolicy;

use App\Domain\AppPolicy\FeatureFlagsContractPolicy;
use PHPUnit\Framework\TestCase;

final class FeatureFlagsContractPolicyTest extends TestCase
{
    public function testNormalizedAcceptedVersionsFiltersInvalidAndIncludesLatest(): void
    {
        $policy = new FeatureFlagsContractPolicy();

        $versions = $policy->normalizedAcceptedVersions('1.0.0', ['0.9.0', 'abc', '1.0', '1.0.0', 123]);

        self::assertSame(['0.9.0', '1.0.0'], $versions);
    }

    public function testSupportAndCompatibilityRules(): void
    {
        $policy = new FeatureFlagsContractPolicy();
        $accepted = ['1.0.0', '0.9.0'];

        self::assertTrue($policy->isSupportedClientVersion('', $accepted));
        self::assertTrue($policy->isSupportedClientVersion('0.9.0', $accepted));
        self::assertFalse($policy->isSupportedClientVersion('2.0.0', $accepted));

        self::assertSame('1.0.0', $policy->effectiveVersion('', '1.0.0'));
        self::assertSame('0.9.0', $policy->effectiveVersion('0.9.0', '1.0.0'));

        self::assertSame('STRICT', $policy->compatibilityMode('1.0.0', '1.0.0'));
        self::assertSame('COMPAT', $policy->compatibilityMode('0.9.0', '1.0.0'));
    }
}
