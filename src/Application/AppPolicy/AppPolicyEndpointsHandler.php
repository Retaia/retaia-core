<?php

namespace App\Application\AppPolicy;

use App\Application\Auth\ResolveAdminActorHandler;
use App\Application\Auth\ResolveAdminActorResult;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\ResolveAuthenticatedUserResult;

final class AppPolicyEndpointsHandler
{
    public function __construct(
        private GetAppPolicyHandler $getAppPolicyHandler,
        private GetAppFeaturesHandler $getAppFeaturesHandler,
        private PatchAppFeaturesHandler $patchAppFeaturesHandler,
        private ResolveAuthenticatedUserHandler $resolveAuthenticatedUserHandler,
        private ResolveAdminActorHandler $resolveAdminActorHandler,
    ) {
    }

    public function policy(string $clientContractVersion): AppPolicyEndpointResult
    {
        $policy = $this->getAppPolicyHandler->handle($clientContractVersion);
        if (!$policy->isSupported()) {
            return new AppPolicyEndpointResult(
                AppPolicyEndpointResult::STATUS_UNSUPPORTED_CONTRACT_VERSION,
                $policy->acceptedVersions()
            );
        }

        return new AppPolicyEndpointResult(
            AppPolicyEndpointResult::STATUS_SUCCESS,
            $policy->acceptedVersions(),
            $policy->featureFlags(),
            $policy->latestVersion(),
            $policy->effectiveVersion(),
            $policy->compatibilityMode()
        );
    }

    public function features(): GetAppFeaturesEndpointResult
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new GetAppFeaturesEndpointResult(GetAppFeaturesEndpointResult::STATUS_UNAUTHORIZED);
        }

        $adminActor = $this->resolveAdminActorHandler->handle();
        if ($adminActor->status() === ResolveAdminActorResult::STATUS_FORBIDDEN_ACTOR) {
            return new GetAppFeaturesEndpointResult(GetAppFeaturesEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }

        return new GetAppFeaturesEndpointResult(
            GetAppFeaturesEndpointResult::STATUS_SUCCESS,
            $this->getAppFeaturesHandler->handle()
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function patchFeatures(array $payload): PatchAppFeaturesEndpointResult
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new PatchAppFeaturesEndpointResult(PatchAppFeaturesEndpointResult::STATUS_UNAUTHORIZED);
        }

        $adminActor = $this->resolveAdminActorHandler->handle();
        if ($adminActor->status() === ResolveAdminActorResult::STATUS_FORBIDDEN_ACTOR) {
            return new PatchAppFeaturesEndpointResult(PatchAppFeaturesEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }

        $appFeatureEnabled = $payload['app_feature_enabled'] ?? null;
        if (!is_array($appFeatureEnabled)) {
            return new PatchAppFeaturesEndpointResult(PatchAppFeaturesEndpointResult::STATUS_VALIDATION_FAILED_PAYLOAD);
        }

        $result = $this->patchAppFeaturesHandler->handle($appFeatureEnabled);
        if ($result->status() === PatchAppFeaturesResult::STATUS_VALIDATION_FAILED) {
            return new PatchAppFeaturesEndpointResult(
                PatchAppFeaturesEndpointResult::STATUS_VALIDATION_FAILED,
                $result->validationDetails()
            );
        }

        return new PatchAppFeaturesEndpointResult(
            PatchAppFeaturesEndpointResult::STATUS_UPDATED,
            null,
            $result->features()
        );
    }
}
