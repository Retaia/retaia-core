<?php

namespace App\Controller\Api;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/agents')]
final class AgentController
{
    private const SEMVER_PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+$/';

    public function __construct(
        private Security $security,
        private TranslatorInterface $translator,
        private bool $featureSuggestTagsEnabled,
        private bool $featureSuggestedTagsFiltersEnabled,
        private bool $featureDecisionsBulkEnabled,
        private string $featureFlagsContractVersion,
        private array $acceptedFeatureFlagsContractVersions,
    ) {
    }

    #[Route('/register', name: 'api_agents_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        $agentName = trim((string) ($payload['agent_name'] ?? ''));
        $agentVersion = trim((string) ($payload['agent_version'] ?? ''));
        $capabilities = $payload['capabilities'] ?? null;
        $clientContractVersion = trim((string) ($payload['client_feature_flags_contract_version'] ?? ''));

        if ($agentName === '' || $agentVersion === '' || !is_array($capabilities)) {
            return new JsonResponse(
                [
                    'code' => 'VALIDATION_FAILED',
                    'message' => $this->translator->trans('agent.error.invalid_registration_payload'),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

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

        $user = $this->security->getUser();
        $userId = $user instanceof User ? $user->getId() : 'unknown';

        return new JsonResponse(
            [
                'agent_id' => sprintf('%s:%s', $userId, $agentName),
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
                    'features' => [
                        'ai' => [
                            'suggest_tags' => $this->featureSuggestTagsEnabled,
                            'suggested_tags_filters' => $this->featureSuggestedTagsFiltersEnabled,
                        ],
                        'decisions' => [
                            'bulk' => $this->featureDecisionsBulkEnabled,
                        ],
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
