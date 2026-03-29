<?php

namespace App\Tests\Unit\Feature;

use App\Feature\FeatureExplanationBuilder;
use PHPUnit\Framework\TestCase;

final class FeatureExplanationBuilderTest extends TestCase
{
    public function testBuildsAppFeatureExplanationsWithDependenciesAndEscalation(): void
    {
        $builder = new FeatureExplanationBuilder();
        $rules = [
            'features.ai.suggest_tags' => [
                'dependencies' => [],
                'disable_escalation' => ['features.ai.suggested_tags_filters'],
            ],
            'features.ai.suggested_tags_filters' => [
                'dependencies' => ['features.ai.suggest_tags'],
                'disable_escalation' => [],
            ],
        ];

        $explanations = $builder->appFeatureExplanations(
            [
                'features.ai' => true,
                'features.ai.suggest_tags' => false,
                'features.ai.suggested_tags_filters' => true,
            ],
            [
                'features.ai.suggest_tags' => true,
                'features.ai.suggested_tags_filters' => true,
            ],
            $rules,
            ['features.ai', 'features.ai.suggest_tags', 'features.ai.suggested_tags_filters'],
            ['features.core.auth'],
        );

        self::assertSame('ADMIN_DISABLED', $explanations['features.ai.suggest_tags']['reason_code'] ?? null);
        self::assertSame('DISABLE_ESCALATION', $explanations['features.ai.suggested_tags_filters']['reason_code'] ?? null);
        self::assertSame('CORE_PROTECTED', $explanations['features.core.auth']['reason_code'] ?? null);
    }

    public function testBuildsUserFeatureExplanationsWithUserOptOut(): void
    {
        $builder = new FeatureExplanationBuilder();
        $rules = [
            'features.decisions.bulk' => [
                'dependencies' => [],
                'disable_escalation' => [],
            ],
        ];

        $explanations = $builder->userFeatureExplanations(
            ['features.decisions.bulk' => false],
            ['features.decisions.bulk' => true],
            ['features.decisions.bulk' => true],
            $rules,
            ['features.decisions.bulk'],
            [],
        );

        self::assertSame('USER_OPT_OUT', $explanations['features.decisions.bulk']['reason_code'] ?? null);
        self::assertFalse($explanations['features.decisions.bulk']['effective_value'] ?? true);
    }
}
