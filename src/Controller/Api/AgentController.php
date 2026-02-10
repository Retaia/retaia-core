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
    public function __construct(
        private Security $security,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/register', name: 'api_agents_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        $agentName = trim((string) ($payload['agent_name'] ?? ''));
        $agentVersion = trim((string) ($payload['agent_version'] ?? ''));
        $capabilities = $payload['capabilities'] ?? null;

        if ($agentName === '' || $agentVersion === '' || !is_array($capabilities)) {
            return new JsonResponse(
                [
                    'code' => 'VALIDATION_FAILED',
                    'message' => $this->translator->trans('agent.error.invalid_registration_payload'),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

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
                            'suggest_tags' => false,
                            'suggested_tags_filters' => false,
                        ],
                        'decisions' => [
                            'bulk' => false,
                        ],
                    ],
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
}
