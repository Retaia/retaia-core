<?php

namespace App\Controller\Api;

use App\Application\AppPolicy\GetAppFeaturesHandler;
use App\Application\AppPolicy\PatchAppFeaturesHandler;
use App\Application\AppPolicy\PatchAppFeaturesResult;
use App\Application\AppPolicy\GetAppPolicyHandler;
use App\Application\Auth\ResolveAdminActorHandler;
use App\Application\Auth\ResolveAdminActorResult;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\ResolveAuthenticatedUserResult;
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
        private GetAppPolicyHandler $getAppPolicyHandler,
        private GetAppFeaturesHandler $getAppFeaturesHandler,
        private PatchAppFeaturesHandler $patchAppFeaturesHandler,
        private ResolveAuthenticatedUserHandler $resolveAuthenticatedUserHandler,
        private ResolveAdminActorHandler $resolveAdminActorHandler,
    ) {
    }

    #[Route('/policy', name: 'api_app_policy', methods: ['GET'])]
    public function policy(Request $request): JsonResponse
    {
        $clientContractVersion = trim((string) $request->query->get('client_feature_flags_contract_version', ''));
        $policy = $this->getAppPolicyHandler->handle($clientContractVersion);

        if (!$policy->isSupported()) {
            return new JsonResponse(
                [
                    'code' => 'UNSUPPORTED_FEATURE_FLAGS_CONTRACT_VERSION',
                    'message' => $this->translator->trans('auth.error.unsupported_feature_flags_contract_version'),
                    'details' => [
                        'accepted_feature_flags_contract_versions' => $policy->acceptedVersions(),
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
                        'features.ai.suggest_tags' => $policy->featureFlags()['features.ai.suggest_tags'] ?? false,
                        'features.ai.suggested_tags_filters' => $policy->featureFlags()['features.ai.suggested_tags_filters'] ?? false,
                        'features.decisions.bulk' => $policy->featureFlags()['features.decisions.bulk'] ?? false,
                    ],
                    'feature_flags_contract_version' => $policy->latestVersion(),
                    'accepted_feature_flags_contract_versions' => $policy->acceptedVersions(),
                    'effective_feature_flags_contract_version' => $policy->effectiveVersion(),
                    'feature_flags_compatibility_mode' => $policy->compatibilityMode(),
                ],
            ],
            Response::HTTP_OK
        );
    }

    #[Route('/features', name: 'api_app_features_get', methods: ['GET'])]
    public function features(): JsonResponse
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $adminActor = $this->resolveAdminActorHandler->handle();
        if ($adminActor->status() === ResolveAdminActorResult::STATUS_FORBIDDEN_ACTOR) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_ACTOR', 'message' => $this->translator->trans('auth.error.forbidden_actor')],
                Response::HTTP_FORBIDDEN
            );
        }

        $features = $this->getAppFeaturesHandler->handle();

        return new JsonResponse(
            [
                'app_feature_enabled' => $features->appFeatureEnabled(),
                'feature_governance' => $features->featureGovernance(),
                'core_v1_global_features' => $features->coreV1GlobalFeatures(),
            ],
            Response::HTTP_OK
        );
    }

    #[Route('/features', name: 'api_app_features_patch', methods: ['PATCH'])]
    public function patchFeatures(Request $request): JsonResponse
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $adminActor = $this->resolveAdminActorHandler->handle();
        if ($adminActor->status() === ResolveAdminActorResult::STATUS_FORBIDDEN_ACTOR) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_ACTOR', 'message' => $this->translator->trans('auth.error.forbidden_actor')],
                Response::HTTP_FORBIDDEN
            );
        }

        $payload = $this->payload($request);
        $appFeatureEnabled = $payload['app_feature_enabled'] ?? null;
        if (!is_array($appFeatureEnabled)) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.invalid_app_feature_payload')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->patchAppFeaturesHandler->handle($appFeatureEnabled);
        if ($result->status() === PatchAppFeaturesResult::STATUS_VALIDATION_FAILED) {
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
