<?php

namespace App\Application\Auth;

final class MyFeaturesResult
{
    /**
     * @param array<string, bool>              $userFeatureEnabled
     * @param array<string, bool>              $effectiveFeatureEnabled
     * @param array<int, array<string, mixed>> $featureGovernance
     * @param array<int, string>               $coreV1GlobalFeatures
     */
    public function __construct(
        private array $userFeatureEnabled,
        private array $effectiveFeatureEnabled,
        private array $featureGovernance,
        private array $coreV1GlobalFeatures,
    ) {
    }

    /**
     * @return array<string, bool>
     */
    public function userFeatureEnabled(): array
    {
        return $this->userFeatureEnabled;
    }

    /**
     * @return array<string, bool>
     */
    public function effectiveFeatureEnabled(): array
    {
        return $this->effectiveFeatureEnabled;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function featureGovernance(): array
    {
        return $this->featureGovernance;
    }

    /**
     * @return array<int, string>
     */
    public function coreV1GlobalFeatures(): array
    {
        return $this->coreV1GlobalFeatures;
    }
}
