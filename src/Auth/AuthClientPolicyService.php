<?php

namespace App\Auth;

use App\Feature\FeatureGovernanceService;

final class AuthClientPolicyService
{
    public function __construct(
        private FeatureGovernanceService $featureGovernanceService,
    ) {
    }

    public function isMcpDisabledByAppPolicy(): bool
    {
        $appFeatures = $this->featureGovernanceService->appFeatureEnabled();

        return ($appFeatures['features.ai'] ?? true) === false;
    }
}
