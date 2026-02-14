<?php

namespace App\Controller\Api;

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
}
