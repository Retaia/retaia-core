<?php

namespace App\Tests\Unit\Feature;

use App\Feature\FeatureToggleStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class FeatureToggleStoreTest extends TestCase
{
    public function testStoresNormalizedAppAndUserFeatureMaps(): void
    {
        $store = new FeatureToggleStore(new ArrayAdapter());

        self::assertSame([
            'features.ai' => true,
            'features.decisions.bulk' => false,
        ], $store->appFeatureEnabled([
            'features.ai' => true,
            'features.decisions.bulk' => false,
        ]));

        $store->setAppFeatureEnabled([
            'features.ai' => 0,
            'features.decisions.bulk' => '1',
        ]);
        self::assertSame([
            'features.ai' => false,
            'features.decisions.bulk' => true,
        ], $store->appFeatureEnabled([]));

        $store->setUserFeatureEnabled('user-1', ['features.ai.suggest_tags' => 0]);
        self::assertSame([
            'features.ai.suggest_tags' => false,
        ], $store->userFeatureEnabled('user-1'));
    }
}
