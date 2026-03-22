<?php

namespace App\Feature;

use Psr\Cache\CacheItemPoolInterface;

final class FeatureGovernanceService
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
        private CacheItemPoolInterface $cache,
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
     * @param array<string, mixed> $features
     * @param array<int, string>   $allowedKeys
     *
     * @return array{unknown_keys: array<int, string>, non_boolean_keys: array<int, string>}
     */
    public function validateFeaturePayload(array $features, array $allowedKeys): array
    {
        $allowedLookup = array_fill_keys($allowedKeys, true);
        $unknownKeys = [];
        $nonBooleanKeys = [];

        foreach ($features as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (!array_key_exists($key, $allowedLookup)) {
                $unknownKeys[] = $key;
                continue;
            }
            if (!is_bool($value)) {
                $nonBooleanKeys[] = $key;
            }
        }

        sort($unknownKeys);
        sort($nonBooleanKeys);

        return [
            'unknown_keys' => $unknownKeys,
            'non_boolean_keys' => $nonBooleanKeys,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function appFeatureEnabled(): array
    {
        $item = $this->cache->getItem('features_app_enabled');
        $value = $item->get();
        if (is_array($value)) {
            return $this->normalizeBooleanMap($value);
        }

        return [
            'features.ai' => true,
            'features.ai.suggest_tags' => $this->featureSuggestTagsEnabled,
            'features.ai.suggested_tags_filters' => $this->featureSuggestedTagsFiltersEnabled,
            'features.decisions.bulk' => $this->featureDecisionsBulkEnabled,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function appFeatureExplanations(): array
    {
        $appEnabled = $this->appFeatureEnabled();
        $featureFlags = $this->featureFlags();
        $rules = $this->ruleMap();
        $explanations = [];

        foreach (self::CORE_V1_GLOBAL_FEATURES as $coreFeature) {
            $explanations[$coreFeature] = [
                'effective_value' => true,
                'reason_code' => 'CORE_PROTECTED',
            ];
        }

        foreach ($this->allowedAppFeatureKeys() as $key) {
            $effective = (bool) ($appEnabled[$key] ?? true);
            $explanation = ['effective_value' => $effective];

            if ($key === 'features.ai') {
                if ($effective === false) {
                    $explanation['reason_code'] = 'ADMIN_DISABLED';
                }
                $explanations[$key] = $explanation;
                continue;
            }

            if (($featureFlags[$key] ?? true) === false) {
                $explanation['effective_value'] = false;
                $explanation['reason_code'] = 'FEATURE_FLAG_OFF';
                $explanations[$key] = $explanation;
                continue;
            }

            if (str_starts_with($key, 'features.ai.') && ($appEnabled['features.ai'] ?? true) === false) {
                $explanation['effective_value'] = false;
                $explanation['reason_code'] = 'ADMIN_DISABLED';
                $explanation['parent_feature_key'] = 'features.ai';
                $explanations[$key] = $explanation;
                continue;
            }

            if (($appEnabled[$key] ?? true) === false) {
                $explanation['effective_value'] = false;
                $explanation['reason_code'] = 'ADMIN_DISABLED';
                $explanations[$key] = $explanation;
                continue;
            }

            foreach ((array) ($rules[$key]['dependencies'] ?? []) as $dependency) {
                $dependencyKey = (string) $dependency;
                if (($appEnabled[$dependencyKey] ?? false) === false || ($featureFlags[$dependencyKey] ?? true) === false) {
                    $explanation['effective_value'] = false;
                    $explanation['reason_code'] = 'DEPENDENCY_OFF';
                    $explanation['dependency_key'] = $dependencyKey;
                    break;
                }
            }

            $explanations[$key] = $explanation;
        }

        foreach ($this->disableEscalatedChildren($rules, $explanations) as $key => $explanation) {
            $explanations[$key] = $explanation;
        }

        return $explanations;
    }

    /**
     * @param array<string, mixed> $features
     */
    public function setAppFeatureEnabled(array $features): void
    {
        $item = $this->cache->getItem('features_app_enabled');
        $item->set($this->normalizeBooleanMap($features));
        $this->cache->save($item);
    }

    /**
     * @return array<string, bool>
     */
    public function userFeatureEnabled(string $userId): array
    {
        $item = $this->cache->getItem($this->userFeaturesKey($userId));
        $value = $item->get();

        return is_array($value) ? $this->normalizeBooleanMap($value) : [];
    }

    /**
     * @param array<string, mixed> $features
     */
    public function setUserFeatureEnabled(string $userId, array $features): void
    {
        $item = $this->cache->getItem($this->userFeaturesKey($userId));
        $item->set($this->normalizeBooleanMap($features));
        $this->cache->save($item);
    }

    /**
     * @return array<string, bool>
     */
    public function effectiveFeatureEnabledForUser(string $userId): array
    {
        $effective = [];
        foreach ($this->effectiveFeatureExplanationsForUser($userId) as $key => $explanation) {
            $effective[$key] = (bool) ($explanation['effective_value'] ?? false);
        }

        return $effective;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function effectiveFeatureExplanationsForUser(string $userId): array
    {
        $featureFlags = $this->featureFlags();
        $appEnabled = $this->appFeatureEnabled();
        $userEnabled = $this->userFeatureEnabled($userId);
        $rules = $this->ruleMap();

        $explanations = [];
        foreach (self::CORE_V1_GLOBAL_FEATURES as $coreFeature) {
            $explanations[$coreFeature] = [
                'effective_value' => true,
                'reason_code' => 'CORE_PROTECTED',
            ];
        }

        foreach ($this->allowedUserFeatureKeys() as $key) {
            $explanation = ['effective_value' => true];

            if (($featureFlags[$key] ?? false) === false) {
                $explanation['effective_value'] = false;
                $explanation['reason_code'] = 'FEATURE_FLAG_OFF';
                $explanations[$key] = $explanation;
                continue;
            }

            if (str_starts_with($key, 'features.ai.') && ($appEnabled['features.ai'] ?? true) === false) {
                $explanation['effective_value'] = false;
                $explanation['reason_code'] = 'ADMIN_DISABLED';
                $explanation['parent_feature_key'] = 'features.ai';
                $explanations[$key] = $explanation;
                continue;
            }

            if (($appEnabled[$key] ?? true) === false) {
                $explanation['effective_value'] = false;
                $explanation['reason_code'] = 'ADMIN_DISABLED';
                $explanations[$key] = $explanation;
                continue;
            }

            if (($userEnabled[$key] ?? true) === false) {
                $explanation['effective_value'] = false;
                $explanation['reason_code'] = 'USER_OPT_OUT';
                $explanations[$key] = $explanation;
                continue;
            }

            foreach ((array) ($rules[$key]['dependencies'] ?? []) as $dependency) {
                $dependencyKey = (string) $dependency;
                if ((bool) ($explanations[$dependencyKey]['effective_value'] ?? false) === false) {
                    $explanation['effective_value'] = false;
                    $explanation['reason_code'] = 'DEPENDENCY_OFF';
                    $explanation['dependency_key'] = $dependencyKey;
                    break;
                }
            }

            $explanations[$key] = $explanation;
        }

        foreach ($this->disableEscalatedChildren($rules, $explanations) as $key => $explanation) {
            $explanations[$key] = $explanation;
        }

        return $explanations;
    }

    /**
     * @param array<string, mixed> $map
     * @return array<string, bool>
     */
    private function normalizeBooleanMap(array $map): array
    {
        $normalized = [];
        foreach ($map as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $normalized[$key] = (bool) $value;
        }

        return $normalized;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function ruleMap(): array
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

    /**
     * @param array<string, array<string, mixed>> $rules
     * @param array<string, array<string, mixed>> $explanations
     * @return array<string, array<string, mixed>>
     */
    private function disableEscalatedChildren(array $rules, array $explanations): array
    {
        foreach ($rules as $key => $rule) {
            if ((bool) ($explanations[$key]['effective_value'] ?? false) !== false) {
                continue;
            }

            foreach ((array) ($rule['disable_escalation'] ?? []) as $child) {
                $childKey = (string) $child;
                $explanations[$childKey] = [
                    'effective_value' => false,
                    'reason_code' => 'DISABLE_ESCALATION',
                    'parent_feature_key' => $key,
                ];
            }
        }

        return $explanations;
    }

    private function userFeaturesKey(string $userId): string
    {
        return 'features_user_'.sha1($userId);
    }
}
