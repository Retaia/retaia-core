<?php

namespace App\Controller\Api;

use App\Application\AppPolicy\AppPolicyEndpointsHandler;
use App\Application\AppPolicy\AppPolicyEndpointResult;
use App\Application\AppPolicy\GetAppFeaturesEndpointResult;
use App\Application\AppPolicy\PatchAppFeaturesEndpointResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/app')]
final class AppController
{
    public function __construct(
        private TranslatorInterface $translator,
        private AppPolicyEndpointsHandler $appPolicyEndpointsHandler,
    ) {
    }

    #[Route('/policy', name: 'api_app_policy', methods: ['GET'])]
    public function policy(Request $request): JsonResponse
    {
        $clientContractVersion = trim((string) $request->query->get('client_feature_flags_contract_version', ''));
        $result = $this->appPolicyEndpointsHandler->policy($clientContractVersion);

        if ($result->status() === AppPolicyEndpointResult::STATUS_UNSUPPORTED_CONTRACT_VERSION) {
            return new JsonResponse(
                [
                    'code' => 'UNSUPPORTED_FEATURE_FLAGS_CONTRACT_VERSION',
                    'message' => $this->translator->trans('auth.error.unsupported_feature_flags_contract_version'),
                    'details' => [
                        'accepted_feature_flags_contract_versions' => $result->acceptedVersions(),
                    ],
                ],
                Response::HTTP_UPGRADE_REQUIRED
            );
        }

        return new JsonResponse(
            [
                'server_policy' => [
                    'min_poll_interval_seconds' => 5,
                    'max_parallel_jobs_allowed' => 8,
                    'allowed_job_types' => [
                        'extract_facts',
                        'generate_proxy',
                        'generate_thumbnails',
                        'generate_audio_waveform',
                        'transcribe_audio',
                    ],
                    'feature_flags' => [
                        'features.ai.suggest_tags' => $result->featureFlags()['features.ai.suggest_tags'] ?? false,
                        'features.ai.suggested_tags_filters' => $result->featureFlags()['features.ai.suggested_tags_filters'] ?? false,
                        'features.decisions.bulk' => $result->featureFlags()['features.decisions.bulk'] ?? false,
                    ],
                    'feature_flags_contract_version' => $result->latestVersion(),
                    'accepted_feature_flags_contract_versions' => $result->acceptedVersions(),
                    'effective_feature_flags_contract_version' => $result->effectiveVersion(),
                    'feature_flags_compatibility_mode' => $result->compatibilityMode(),
                ],
            ],
            Response::HTTP_OK
        );
    }

    #[Route('/features', name: 'api_app_features_get', methods: ['GET'])]
    public function features(): JsonResponse
    {
        $result = $this->appPolicyEndpointsHandler->features();
        if ($result->status() === GetAppFeaturesEndpointResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }
        if ($result->status() === GetAppFeaturesEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_ACTOR', 'message' => $this->translator->trans('auth.error.forbidden_actor')],
                Response::HTTP_FORBIDDEN
            );
        }
        $features = $result->features();

        return new JsonResponse(
            [
                'app_feature_enabled' => $features?->appFeatureEnabled() ?? [],
                'feature_governance' => $features?->featureGovernance() ?? [],
                'core_v1_global_features' => $features?->coreV1GlobalFeatures() ?? [],
            ],
            Response::HTTP_OK
        );
    }

    #[Route('/features', name: 'api_app_features_patch', methods: ['PATCH'])]
    public function patchFeatures(Request $request): JsonResponse
    {
        $result = $this->appPolicyEndpointsHandler->patchFeatures($this->payload($request));
        if ($result->status() === PatchAppFeaturesEndpointResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }
        if ($result->status() === PatchAppFeaturesEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_ACTOR', 'message' => $this->translator->trans('auth.error.forbidden_actor')],
                Response::HTTP_FORBIDDEN
            );
        }
        if ($result->status() === PatchAppFeaturesEndpointResult::STATUS_VALIDATION_FAILED_PAYLOAD) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.invalid_app_feature_payload')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if ($result->status() === PatchAppFeaturesEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse(
                [
                    'code' => 'VALIDATION_FAILED',
                    'message' => $this->translator->trans('auth.error.invalid_app_feature_payload'),
                    'details' => $result->validationDetails(),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $features = $result->features();

        return new JsonResponse(
            [
                'app_feature_enabled' => $features?->appFeatureEnabled() ?? [],
                'feature_governance' => $features?->featureGovernance() ?? [],
                'core_v1_global_features' => $features?->coreV1GlobalFeatures() ?? [],
            ],
            Response::HTTP_OK
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        if ($request->getContent() === '') {
            return [];
        }

        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
