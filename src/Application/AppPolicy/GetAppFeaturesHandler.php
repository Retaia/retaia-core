<?php

namespace App\Application\AppPolicy;

use App\Application\AppPolicy\Port\AppFeatureGovernanceGateway;

final class GetAppFeaturesHandler
{
    public function __construct(
        private AppFeatureGovernanceGateway $gateway,
    ) {
    }

    public function handle(): GetAppFeaturesResult
    {
        return new GetAppFeaturesResult(
            $this->gateway->appFeatureEnabled(),
            $this->gateway->featureGovernanceRules(),
            $this->gateway->coreV1GlobalFeatures()
        );
    }
}
