<?php

namespace App\Application\Auth;

use App\Application\Auth\Port\FeatureGovernanceGateway;

final class GetMyFeaturesHandler
{
    public function __construct(
        private FeatureGovernanceGateway $gateway,
    ) {
    }

    public function handle(string $userId): MyFeaturesResult
    {
        return new MyFeaturesResult(
            $this->gateway->userFeatureEnabled($userId),
            $this->gateway->effectiveFeatureEnabledForUser($userId),
            $this->gateway->featureGovernanceRules(),
            $this->gateway->coreV1GlobalFeatures()
        );
    }
}
