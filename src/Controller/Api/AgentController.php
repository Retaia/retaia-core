<?php

namespace App\Controller\Api;

use App\Application\Agent\RegisterAgentEndpointHandler;
use App\Application\Agent\RegisterAgentEndpointResult;
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
        private RegisterAgentEndpointHandler $registerAgentEndpointHandler,
    ) {
    }

    #[Route('/register', name: 'api_agents_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $result = $this->registerAgentEndpointHandler->handle($this->payload($request));
        if ($result->status() === RegisterAgentEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse(
                [
                    'code' => 'VALIDATION_FAILED',
                    'message' => $this->translator->trans('agent.error.invalid_registration_payload'),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($result->status() === RegisterAgentEndpointResult::STATUS_UNSUPPORTED_CONTRACT_VERSION) {
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
