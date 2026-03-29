<?php

namespace App\Feature;

final class FeatureGovernanceRulesProvider
{
    /**
     * @var array<int, string>
     */
    private const CORE_V1_GLOBAL_FEATURES = [
        'features.core.auth',
        'features.core.assets.lifecycle',
        'features.core.jobs.runtime',
        'features.core.search.query',
        'features.core.policy.runtime',
        'features.core.derived.access',
        'features.core.clients.bootstrap',
    ];

    public function __construct(
        private bool $featureSuggestTagsEnabled,
        private bool $featureSuggestedTagsFiltersEnabled,
        private bool $featureDecisionsBulkEnabled,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function featureGovernanceRules(): array
    {
        $rules = [];
        foreach (self::CORE_V1_GLOBAL_FEATURES as $coreFeature) {
            $rules[] = [
                'key' => $coreFeature,
                'tier' => 'CORE_V1_GLOBAL',
                'user_can_disable' => false,
                'dependencies' => [],
                'disable_escalation' => [],
            ];
        }

        $rules[] = [
            'key' => 'features.ai.suggest_tags',
            'tier' => 'OPTIONAL',
            'user_can_disable' => true,
            'dependencies' => [],
            'disable_escalation' => ['features.ai.suggested_tags_filters'],
        ];
        $rules[] = [
            'key' => 'features.ai.suggested_tags_filters',
            'tier' => 'OPTIONAL',
            'user_can_disable' => true,
            'dependencies' => ['features.ai.suggest_tags'],
            'disable_escalation' => [],
        ];
        $rules[] = [
            'key' => 'features.decisions.bulk',
            'tier' => 'OPTIONAL',
            'user_can_disable' => true,
            'dependencies' => [],
            'disable_escalation' => [],
        ];

        return $rules;
    }

    /**
     * @return array<int, string>
     */
    public function coreV1GlobalFeatures(): array
    {
        return self::CORE_V1_GLOBAL_FEATURES;
    }

    /**
     * @return array<string, bool>
     */
    public function featureFlags(): array
    {
        return [
            'features.ai.suggest_tags' => $this->featureSuggestTagsEnabled,
            'features.ai.suggested_tags_filters' => $this->featureSuggestedTagsFiltersEnabled,
            'features.decisions.bulk' => $this->featureDecisionsBulkEnabled,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function allowedAppFeatureKeys(): array
    {
        return [
            'features.ai',
            'features.ai.suggest_tags',
            'features.ai.suggested_tags_filters',
            'features.decisions.bulk',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function allowedUserFeatureKeys(): array
    {
        $keys = [];
        foreach ($this->featureGovernanceRules() as $rule) {
            if ((bool) ($rule['user_can_disable'] ?? false) !== true) {
                continue;
            }
            $key = $rule['key'] ?? null;
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function ruleMap(): array
    {
        $rules = [];
        foreach ($this->featureGovernanceRules() as $rule) {
            $key = (string) ($rule['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $rules[$key] = $rule;
        }

        return $rules;
    }
}
