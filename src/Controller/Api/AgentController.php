<?php

namespace App\Controller\Api;

use App\Application\Agent\RegisterAgentHandler;
use App\Application\Agent\RegisterAgentResult;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\ResolveAuthenticatedUserResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/agents')]
final class AgentController
{
    public function __construct(
        private TranslatorInterface $translator,
        private RegisterAgentHandler $registerAgentHandler,
        private ResolveAuthenticatedUserHandler $resolveAuthenticatedUserHandler,
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

        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        $actorId = $authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_AUTHENTICATED
            ? (string) $authenticatedUser->id()
            : 'unknown';

        $result = $this->registerAgentHandler->handle($actorId, $agentName, $clientContractVersion);
        if ($result->status() === RegisterAgentResult::STATUS_UNSUPPORTED_CONTRACT_VERSION) {
            return new JsonResponse(
                [
                    'code' => 'UNSUPPORTED_FEATURE_FLAGS_CONTRACT_VERSION',
                    'message' => $this->translator->trans('auth.error.unsupported_feature_flags_contract_version'),
                    'details' => [
                        'accepted_feature_flags_contract_versions' => $result->acceptedFeatureFlagsContractVersions(),
                    ],
                ],
                Response::HTTP_UPGRADE_REQUIRED
            );
        }

        return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
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
