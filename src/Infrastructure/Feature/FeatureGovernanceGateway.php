<?php

namespace App\Infrastructure\Feature;

use App\Application\Auth\Port\FeatureGovernanceGateway as FeatureGovernanceGatewayPort;
use App\Feature\FeatureGovernanceService;

final class FeatureGovernanceGateway implements FeatureGovernanceGatewayPort
{
    public function __construct(
        private FeatureGovernanceService $service,
    ) {
    }

    public function userFeatureEnabled(string $userId): array
    {
        return $this->service->userFeatureEnabled($userId);
    }

    public function effectiveFeatureEnabledForUser(string $userId): array
    {
        return $this->service->effectiveFeatureEnabledForUser($userId);
    }

    public function featureGovernanceRules(): array
    {
        return $this->service->featureGovernanceRules();
    }

    public function coreV1GlobalFeatures(): array
    {
        return $this->service->coreV1GlobalFeatures();
    }

    public function allowedUserFeatureKeys(): array
    {
        return $this->service->allowedUserFeatureKeys();
    }

    public function validateFeaturePayload(array $features, array $allowedKeys): array
    {
        return $this->service->validateFeaturePayload($features, $allowedKeys);
    }

    public function setUserFeatureEnabled(string $userId, array $features): void
    {
        $this->service->setUserFeatureEnabled($userId, $features);
    }
}
