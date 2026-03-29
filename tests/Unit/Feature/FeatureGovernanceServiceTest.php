<?php

namespace App\Tests\Unit\Feature;

use App\Feature\FeatureExplanationBuilder;
use App\Feature\FeatureGovernanceRulesProvider;
use App\Feature\FeatureGovernanceService;
use App\Feature\FeaturePayloadValidator;
use App\Feature\FeatureToggleStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class FeatureGovernanceServiceTest extends TestCase
{
    public function testFacadeDelegatesToExtractedResponsibilities(): void
    {
        $service = new FeatureGovernanceService(
            new FeatureGovernanceRulesProvider(true, false, true),
            new FeaturePayloadValidator(),
            new FeatureToggleStore(new ArrayAdapter()),
            new FeatureExplanationBuilder(),
        );

        self::assertContains('features.decisions.bulk', $service->allowedUserFeatureKeys());
        self::assertSame([
            'unknown_keys' => ['features.unknown'],
            'non_boolean_keys' => ['features.ai.suggest_tags'],
        ], $service->validateFeaturePayload([
            'features.unknown' => true,
            'features.ai.suggest_tags' => 'yes',
        ], ['features.ai.suggest_tags']));

        $service->setAppFeatureEnabled(['features.ai' => false]);
        self::assertSame('ADMIN_DISABLED', $service->appFeatureExplanations()['features.ai']['reason_code'] ?? null);

        $service->setUserFeatureEnabled('user-1', ['features.decisions.bulk' => false]);
        self::assertSame('USER_OPT_OUT', $service->effectiveFeatureExplanationsForUser('user-1')['features.decisions.bulk']['reason_code'] ?? null);
    }
}
