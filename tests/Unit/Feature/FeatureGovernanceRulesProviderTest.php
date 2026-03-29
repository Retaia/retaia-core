<?php

namespace App\Tests\Unit\Feature;

use App\Feature\FeatureGovernanceRulesProvider;
use PHPUnit\Framework\TestCase;

final class FeatureGovernanceRulesProviderTest extends TestCase
{
    public function testProvidesRulesFlagsAndAllowedKeys(): void
    {
        $provider = new FeatureGovernanceRulesProvider(true, false, true);

        self::assertContains('features.core.auth', $provider->coreV1GlobalFeatures());
        self::assertSame([
            'features.ai.suggest_tags' => true,
            'features.ai.suggested_tags_filters' => false,
            'features.decisions.bulk' => true,
        ], $provider->featureFlags());
        self::assertSame([
            'features.ai',
            'features.ai.suggest_tags',
            'features.ai.suggested_tags_filters',
            'features.decisions.bulk',
        ], $provider->allowedAppFeatureKeys());
        self::assertSame([
            'features.ai.suggest_tags',
            'features.ai.suggested_tags_filters',
            'features.decisions.bulk',
        ], $provider->allowedUserFeatureKeys());
        self::assertArrayHasKey('features.ai.suggest_tags', $provider->ruleMap());
    }
}
