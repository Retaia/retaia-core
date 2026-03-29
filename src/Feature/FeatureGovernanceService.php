<?php

namespace App\Feature;

final class FeatureGovernanceService
{
    public function __construct(
        private FeatureGovernanceRulesProvider $rulesProvider,
        private FeaturePayloadValidator $payloadValidator,
        private FeatureToggleStore $toggleStore,
        private FeatureExplanationBuilder $explanationBuilder,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function featureGovernanceRules(): array
    {
        return $this->rulesProvider->featureGovernanceRules();
    }

    /**
     * @return array<int, string>
     */
    public function coreV1GlobalFeatures(): array
    {
        return $this->rulesProvider->coreV1GlobalFeatures();
    }

    /**
     * @return array<string, bool>
     */
    public function featureFlags(): array
    {
        return $this->rulesProvider->featureFlags();
    }

    /**
     * @return array<int, string>
     */
    public function allowedAppFeatureKeys(): array
    {
        return $this->rulesProvider->allowedAppFeatureKeys();
    }

    /**
     * @return array<int, string>
     */
    public function allowedUserFeatureKeys(): array
    {
        return $this->rulesProvider->allowedUserFeatureKeys();
    }

    /**
     * @param array<string, mixed> $features
     * @param array<int, string>   $allowedKeys
     *
     * @return array{unknown_keys: array<int, string>, non_boolean_keys: array<int, string>}
     */
    public function validateFeaturePayload(array $features, array $allowedKeys): array
    {
        return $this->payloadValidator->validateFeaturePayload($features, $allowedKeys);
    }

    /**
     * @return array<string, bool>
     */
    public function appFeatureEnabled(): array
    {
        return $this->toggleStore->appFeatureEnabled([
            'features.ai' => true,
            'features.ai.suggest_tags' => $this->featureFlags()['features.ai.suggest_tags'],
            'features.ai.suggested_tags_filters' => $this->featureFlags()['features.ai.suggested_tags_filters'],
            'features.decisions.bulk' => $this->featureFlags()['features.decisions.bulk'],
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function appFeatureExplanations(): array
    {
        return $this->explanationBuilder->appFeatureExplanations(
            $this->appFeatureEnabled(),
            $this->featureFlags(),
            $this->rulesProvider->ruleMap(),
            $this->allowedAppFeatureKeys(),
            $this->coreV1GlobalFeatures(),
        );
    }

    /**
     * @param array<string, mixed> $features
     */
    public function setAppFeatureEnabled(array $features): void
    {
        $this->toggleStore->setAppFeatureEnabled($features);
    }

    /**
     * @return array<string, bool>
     */
    public function userFeatureEnabled(string $userId): array
    {
        return $this->toggleStore->userFeatureEnabled($userId);
    }

    /**
     * @param array<string, mixed> $features
     */
    public function setUserFeatureEnabled(string $userId, array $features): void
    {
        $this->toggleStore->setUserFeatureEnabled($userId, $features);
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
        return $this->explanationBuilder->userFeatureExplanations(
            $this->userFeatureEnabled($userId),
            $this->appFeatureEnabled(),
            $this->featureFlags(),
            $this->rulesProvider->ruleMap(),
            $this->allowedUserFeatureKeys(),
            $this->coreV1GlobalFeatures(),
        );
    }
}
