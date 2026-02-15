<?php

namespace App\Application\AppPolicy\Port;

interface AppFeatureGovernanceGateway
{
    /**
     * @return array<string, bool>
     */
    public function appFeatureEnabled(): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function featureGovernanceRules(): array;

    /**
     * @return array<int, string>
     */
    public function coreV1GlobalFeatures(): array;

    /**
     * @return array<int, string>
     */
    public function allowedAppFeatureKeys(): array;

    /**
     * @param array<string, mixed> $features
     * @param array<int, string>   $allowedKeys
     *
     * @return array{unknown_keys: array<int, string>, non_boolean_keys: array<int, string>}
     */
    public function validateFeaturePayload(array $features, array $allowedKeys): array;

    /**
     * @param array<string, mixed> $features
     */
    public function setAppFeatureEnabled(array $features): void;
}
