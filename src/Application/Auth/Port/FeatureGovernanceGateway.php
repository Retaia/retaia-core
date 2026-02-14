<?php

namespace App\Application\Auth\Port;

interface FeatureGovernanceGateway
{
    /**
     * @return array<string, bool>
     */
    public function userFeatureEnabled(string $userId): array;

    /**
     * @return array<string, bool>
     */
    public function effectiveFeatureEnabledForUser(string $userId): array;

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
    public function allowedUserFeatureKeys(): array;

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
    public function setUserFeatureEnabled(string $userId, array $features): void;
}
