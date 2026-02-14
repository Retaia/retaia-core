<?php

namespace App\Application\Auth;

use App\Application\Auth\Port\FeatureGovernanceGateway;

final class PatchMyFeaturesHandler
{
    public function __construct(
        private FeatureGovernanceGateway $gateway,
        private GetMyFeaturesHandler $getMyFeaturesHandler,
    ) {
    }

    /**
     * @param array<string, mixed> $userFeatures
     */
    public function handle(string $userId, array $userFeatures): PatchMyFeaturesResult
    {
        $coreFeatures = $this->gateway->coreV1GlobalFeatures();
        foreach ($userFeatures as $featureKey => $enabled) {
            if (!is_string($featureKey)) {
                continue;
            }
            if (in_array($featureKey, $coreFeatures, true)) {
                return new PatchMyFeaturesResult(PatchMyFeaturesResult::STATUS_FORBIDDEN_SCOPE);
            }
        }

        $validation = $this->gateway->validateFeaturePayload($userFeatures, $this->gateway->allowedUserFeatureKeys());
        if ($validation['unknown_keys'] !== [] || $validation['non_boolean_keys'] !== []) {
            return new PatchMyFeaturesResult(PatchMyFeaturesResult::STATUS_VALIDATION_FAILED, $validation);
        }

        $this->gateway->setUserFeatureEnabled($userId, $userFeatures);

        return new PatchMyFeaturesResult(
            PatchMyFeaturesResult::STATUS_UPDATED,
            null,
            $this->getMyFeaturesHandler->handle($userId)
        );
    }
}
