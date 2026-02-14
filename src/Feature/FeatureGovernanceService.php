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
        $featureFlags = $this->featureFlags();
        $appEnabled = $this->appFeatureEnabled();
        $userEnabled = $this->userFeatureEnabled($userId);
        $rules = $this->featureGovernanceRules();

        /** @var array<string, bool> $effective */
        $effective = [];
        foreach ($rules as $rule) {
            $key = (string) ($rule['key'] ?? '');
            if ($key === '') {
                continue;
            }

            if (in_array($key, self::CORE_V1_GLOBAL_FEATURES, true)) {
                $effective[$key] = true;
                continue;
            }

            $flag = (bool) ($featureFlags[$key] ?? false);
            $app = (bool) ($appEnabled[$key] ?? true);
            if (str_starts_with($key, 'features.ai.') && array_key_exists('features.ai', $appEnabled)) {
                $app = $app && (bool) $appEnabled['features.ai'];
            }
            $user = (bool) ($userEnabled[$key] ?? true);
            $effective[$key] = $flag && $app && $user;
        }

        foreach ($rules as $rule) {
            $key = (string) ($rule['key'] ?? '');
            if (!array_key_exists($key, $effective) || $effective[$key] === false) {
                continue;
            }
            foreach ((array) ($rule['dependencies'] ?? []) as $dependency) {
                $dependencyKey = (string) $dependency;
                if (($effective[$dependencyKey] ?? false) === false) {
                    $effective[$key] = false;
                    break;
                }
            }
        }

        foreach ($rules as $rule) {
            $key = (string) ($rule['key'] ?? '');
            if (($effective[$key] ?? false) !== false) {
                continue;
            }
            foreach ((array) ($rule['disable_escalation'] ?? []) as $child) {
                $effective[(string) $child] = false;
            }
        }

        return $effective;
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

    private function userFeaturesKey(string $userId): string
    {
        return 'features_user_'.sha1($userId);
    }
}
