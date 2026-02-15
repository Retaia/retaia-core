<?php

namespace App\Application\AppPolicy;

use App\Application\AppPolicy\Port\AppFeatureGovernanceGateway;

final class PatchAppFeaturesHandler
{
    public function __construct(
        private AppFeatureGovernanceGateway $gateway,
        private GetAppFeaturesHandler $getAppFeaturesHandler,
    ) {
    }

    /**
     * @param array<string, mixed> $appFeatureEnabled
     */
    public function handle(array $appFeatureEnabled): PatchAppFeaturesResult
    {
        $validation = $this->gateway->validateFeaturePayload(
            $appFeatureEnabled,
            $this->gateway->allowedAppFeatureKeys()
        );
        if ($validation['unknown_keys'] !== [] || $validation['non_boolean_keys'] !== []) {
            return new PatchAppFeaturesResult(PatchAppFeaturesResult::STATUS_VALIDATION_FAILED, $validation);
        }

        $this->gateway->setAppFeatureEnabled($appFeatureEnabled);

        return new PatchAppFeaturesResult(
            PatchAppFeaturesResult::STATUS_UPDATED,
            null,
            $this->getAppFeaturesHandler->handle()
        );
    }
}
