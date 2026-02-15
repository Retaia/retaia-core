<?php

namespace App\Application\AppPolicy;

final class GetAppFeaturesResult
{
    /**
     * @param array<string, bool>              $appFeatureEnabled
     * @param array<int, array<string, mixed>> $featureGovernance
     * @param array<int, string>               $coreV1GlobalFeatures
     */
    public function __construct(
        private array $appFeatureEnabled,
        private array $featureGovernance,
        private array $coreV1GlobalFeatures,
    ) {
    }

    /**
     * @return array<string, bool>
     */
    public function appFeatureEnabled(): array
    {
        return $this->appFeatureEnabled;
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
