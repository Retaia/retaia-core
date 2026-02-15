<?php

namespace App\Infrastructure\AppPolicy;

use App\Application\AppPolicy\Port\AppFeatureGovernanceGateway as AppFeatureGovernanceGatewayPort;
use App\Feature\FeatureGovernanceService;

final class AppFeatureGovernanceGateway implements AppFeatureGovernanceGatewayPort
{
    public function __construct(
        private FeatureGovernanceService $service,
    ) {
    }

    public function appFeatureEnabled(): array
    {
        return $this->service->appFeatureEnabled();
    }

    public function featureGovernanceRules(): array
    {
        return $this->service->featureGovernanceRules();
    }

    public function coreV1GlobalFeatures(): array
    {
        return $this->service->coreV1GlobalFeatures();
    }

    public function allowedAppFeatureKeys(): array
    {
        return $this->service->allowedAppFeatureKeys();
    }

    public function validateFeaturePayload(array $features, array $allowedKeys): array
    {
        return $this->service->validateFeaturePayload($features, $allowedKeys);
    }

    public function setAppFeatureEnabled(array $features): void
    {
        $this->service->setAppFeatureEnabled($features);
    }
}
