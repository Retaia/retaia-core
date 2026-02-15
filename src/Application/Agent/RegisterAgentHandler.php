<?php

namespace App\Application\Agent;

use App\Domain\AppPolicy\FeatureFlagsContractPolicy;

final class RegisterAgentHandler
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

    public function handle(string $actorId, string $agentName, string $clientContractVersion): RegisterAgentResult
    {
        $acceptedVersions = $this->contractPolicy->normalizedAcceptedVersions(
            $this->featureFlagsContractVersion,
            $this->acceptedFeatureFlagsContractVersions
        );
        $supported = $this->contractPolicy->isSupportedClientVersion($clientContractVersion, $acceptedVersions);
        if (!$supported) {
            return new RegisterAgentResult(
                RegisterAgentResult::STATUS_UNSUPPORTED_CONTRACT_VERSION,
                $acceptedVersions
            );
        }

        $effectiveVersion = $this->contractPolicy->effectiveVersion($clientContractVersion, $this->featureFlagsContractVersion);
        $compatibilityMode = $this->contractPolicy->compatibilityMode($effectiveVersion, $this->featureFlagsContractVersion);

        return new RegisterAgentResult(
            RegisterAgentResult::STATUS_REGISTERED,
            $acceptedVersions,
            [
                'agent_id' => sprintf('%s:%s', $actorId, $agentName),
                'server_policy' => [
                    'min_poll_interval_seconds' => 5,
                    'max_parallel_jobs_allowed' => 8,
                    'allowed_job_types' => [
                        'extract_facts',
                        'generate_proxy',
                        'generate_thumbnails',
                        'generate_audio_waveform',
                        'transcribe_audio',
                    ],
                    'features' => [
                        'ai' => [
                            'suggest_tags' => $this->featureSuggestTagsEnabled,
                            'suggested_tags_filters' => $this->featureSuggestedTagsFiltersEnabled,
                        ],
                        'decisions' => [
                            'bulk' => $this->featureDecisionsBulkEnabled,
                        ],
                    ],
                    'feature_flags' => [
                        'features.ai.suggest_tags' => $this->featureSuggestTagsEnabled,
                        'features.ai.suggested_tags_filters' => $this->featureSuggestedTagsFiltersEnabled,
                        'features.decisions.bulk' => $this->featureDecisionsBulkEnabled,
                    ],
                    'feature_flags_contract_version' => $this->featureFlagsContractVersion,
                    'accepted_feature_flags_contract_versions' => $acceptedVersions,
                    'effective_feature_flags_contract_version' => $effectiveVersion,
                    'feature_flags_compatibility_mode' => $compatibilityMode,
                ],
            ]
        );
    }
}
