<?php

namespace App\Application\AppPolicy;

use App\Domain\AppPolicy\FeatureFlagsContractPolicy;

final class GetAppPolicyHandler
{
    /**
     * @param array<int, string> $acceptedFeatureFlagsContractVersions
     */
    public function __construct(
        private FeatureFlagsContractPolicy $contractPolicy,
        private bool $featureSuggestTagsEnabled,
        private bool $featureSuggestedTagsFiltersEnabled,
        private bool $featureDecisionsBulkEnabled,
        private string $featureFlagsContractVersion,
        private array $acceptedFeatureFlagsContractVersions,
    ) {
    }

    public function handle(string $clientContractVersion): GetAppPolicyResult
    {
        $acceptedVersions = $this->contractPolicy->normalizedAcceptedVersions(
            $this->featureFlagsContractVersion,
            $this->acceptedFeatureFlagsContractVersions
        );
        $supported = $this->contractPolicy->isSupportedClientVersion($clientContractVersion, $acceptedVersions);
        $effectiveVersion = $this->contractPolicy->effectiveVersion($clientContractVersion, $this->featureFlagsContractVersion);
        $compatibilityMode = $this->contractPolicy->compatibilityMode($effectiveVersion, $this->featureFlagsContractVersion);

        return new GetAppPolicyResult(
            $supported,
            $acceptedVersions,
            $this->featureFlagsContractVersion,
            $effectiveVersion,
            $compatibilityMode,
            [
                'features.ai.suggest_tags' => $this->featureSuggestTagsEnabled,
                'features.ai.suggested_tags_filters' => $this->featureSuggestedTagsFiltersEnabled,
                'features.decisions.bulk' => $this->featureDecisionsBulkEnabled,
            ]
        );
    }
}
