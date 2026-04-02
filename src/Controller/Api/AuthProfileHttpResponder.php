<?php

namespace App\Controller\Api;

use App\Application\Auth\GetMyFeaturesEndpointResult;
use App\Application\Auth\MyFeaturesResult;
use App\Application\Auth\PatchMyFeaturesEndpointResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AuthProfileHttpResponder
{
    public function __construct(
        private AuthApiErrorResponder $errors,
    ) {
    }

    public function meFeatures(GetMyFeaturesEndpointResult|PatchMyFeaturesEndpointResult $result): JsonResponse
    {
        if ($result->status() === GetMyFeaturesEndpointResult::STATUS_UNAUTHORIZED || $result->status() === PatchMyFeaturesEndpointResult::STATUS_UNAUTHORIZED) {
            return $this->errors->unauthorizedAuthenticationRequired();
        }
        if ($result instanceof PatchMyFeaturesEndpointResult) {
            if ($result->status() === PatchMyFeaturesEndpointResult::STATUS_VALIDATION_FAILED_PAYLOAD) {
                return $this->errors->validationFailed('auth.error.invalid_user_feature_payload');
            }
            if ($result->status() === PatchMyFeaturesEndpointResult::STATUS_FORBIDDEN_SCOPE) {
                return $this->errors->forbiddenScope();
            }
            if ($result->status() === PatchMyFeaturesEndpointResult::STATUS_VALIDATION_FAILED) {
                return $this->errors->validationFailedWithDetails('auth.error.invalid_user_feature_payload', $result->validationDetails() ?? []);
            }
        }

        return new JsonResponse($this->featuresPayload($result->features()), Response::HTTP_OK);
    }

    private function featuresPayload(?MyFeaturesResult $features): array
    {
        return [
            'user_feature_enabled' => $features?->userFeatureEnabled() ?? [],
            'effective_feature_enabled' => $features?->effectiveFeatureEnabled() ?? [],
            'effective_feature_explanations' => $features?->effectiveFeatureExplanations() ?? [],
            'feature_governance' => $features?->featureGovernance() ?? [],
            'core_v1_global_features' => $features?->coreV1GlobalFeatures() ?? [],
        ];
    }
}
