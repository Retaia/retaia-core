<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Feature\FeatureGovernanceService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/app')]
final class AppController
{
    private const SEMVER_PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+$/';

    /**
     * @param array<int, string> $acceptedFeatureFlagsContractVersions
     */
    public function __construct(
        private Security $security,
        private FeatureGovernanceService $featureGovernanceService,
        private TranslatorInterface $translator,
        private bool $featureSuggestTagsEnabled,
        private bool $featureSuggestedTagsFiltersEnabled,
        private bool $featureDecisionsBulkEnabled,
        private string $featureFlagsContractVersion,
        private array $acceptedFeatureFlagsContractVersions,
    ) {
    }

    #[Route('/policy', name: 'api_app_policy', methods: ['GET'])]
    public function policy(Request $request): JsonResponse
    {
        $clientContractVersion = trim((string) $request->query->get('client_feature_flags_contract_version', ''));
        $acceptedVersions = $this->normalizedAcceptedVersions();

        if ($clientContractVersion !== '' && !in_array($clientContractVersion, $acceptedVersions, true)) {
            return new JsonResponse(
                [
                    'code' => 'UNSUPPORTED_FEATURE_FLAGS_CONTRACT_VERSION',
                    'message' => $this->translator->trans('auth.error.unsupported_feature_flags_contract_version'),
                    'details' => [
                        'accepted_feature_flags_contract_versions' => $acceptedVersions,
                    ],
                ],
                Response::HTTP_UPGRADE_REQUIRED
            );
        }

        $effectiveVersion = $clientContractVersion !== '' ? $clientContractVersion : $this->featureFlagsContractVersion;
        $compatibilityMode = $effectiveVersion === $this->featureFlagsContractVersion ? 'STRICT' : 'COMPAT';

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
                        'features.ai.suggest_tags' => $this->featureSuggestTagsEnabled,
                        'features.ai.suggested_tags_filters' => $this->featureSuggestedTagsFiltersEnabled,
                        'features.decisions.bulk' => $this->featureDecisionsBulkEnabled,
                    ],
                    'feature_flags_contract_version' => $this->featureFlagsContractVersion,
                    'accepted_feature_flags_contract_versions' => $acceptedVersions,
                    'effective_feature_flags_contract_version' => $effectiveVersion,
                    'feature_flags_compatibility_mode' => $compatibilityMode,
                ],
            ],
            Response::HTTP_OK
        );
    }

    #[Route('/features', name: 'api_app_features_get', methods: ['GET'])]
    public function features(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_ACTOR', 'message' => $this->translator->trans('auth.error.forbidden_actor')],
                Response::HTTP_FORBIDDEN
            );
        }

        return new JsonResponse(
            [
                'app_feature_enabled' => $this->featureGovernanceService->appFeatureEnabled(),
                'feature_governance' => $this->featureGovernanceService->featureGovernanceRules(),
                'core_v1_global_features' => $this->featureGovernanceService->coreV1GlobalFeatures(),
            ],
            Response::HTTP_OK
        );
    }

    #[Route('/features', name: 'api_app_features_patch', methods: ['PATCH'])]
    public function patchFeatures(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }
        if (!$this->security->isGranted('ROLE_ADMIN')) {
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

        $this->featureGovernanceService->setAppFeatureEnabled($appFeatureEnabled);

        return new JsonResponse(
            [
                'app_feature_enabled' => $this->featureGovernanceService->appFeatureEnabled(),
                'feature_governance' => $this->featureGovernanceService->featureGovernanceRules(),
                'core_v1_global_features' => $this->featureGovernanceService->coreV1GlobalFeatures(),
            ],
            Response::HTTP_OK
        );
    }

    /**
     * @return array<int, string>
     */
    private function normalizedAcceptedVersions(): array
    {
        $acceptedVersions = [];
        foreach ($this->acceptedFeatureFlagsContractVersions as $version) {
            if (!is_string($version) || !preg_match(self::SEMVER_PATTERN, $version)) {
                continue;
            }
            $acceptedVersions[] = $version;
        }

        if (!in_array($this->featureFlagsContractVersion, $acceptedVersions, true)) {
            $acceptedVersions[] = $this->featureFlagsContractVersion;
        }

        return array_values(array_unique($acceptedVersions));
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
