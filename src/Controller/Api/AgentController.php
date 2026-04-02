<?php

namespace App\Controller\Api;

use App\Api\Service\SignedAgentRequestValidator;
use App\Application\Agent\RegisterAgentEndpointHandler;
use App\Application\Agent\RegisterAgentEndpointResult;
use App\Controller\RequestPayloadTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/agents')]
final class AgentController
{
    use ApiErrorResponderTrait;
    use RequestPayloadTrait;

    public function __construct(
        private TranslatorInterface $translator,
        private RegisterAgentEndpointHandler $registerAgentEndpointHandler,
        private SignedAgentRequestValidator $signedAgentRequestValidator,
    ) {
    }

    #[Route('/register', name: 'api_agents_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        $signatureViolation = $this->signedAgentRequestValidator->violationResponse($request, $payload);
        if ($signatureViolation instanceof JsonResponse) {
            return $signatureViolation;
        }

        $result = $this->registerAgentEndpointHandler->handle($payload);
        if ($result->status() === RegisterAgentEndpointResult::STATUS_VALIDATION_FAILED) {
            return $this->errorResponse('VALIDATION_FAILED', $this->translator->trans('agent.error.invalid_registration_payload'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($result->status() === RegisterAgentEndpointResult::STATUS_UNSUPPORTED_CONTRACT_VERSION) {
            return $this->errorResponse(
                'UNSUPPORTED_FEATURE_FLAGS_CONTRACT_VERSION',
                $this->translator->trans('auth.error.unsupported_feature_flags_contract_version'),
                Response::HTTP_UPGRADE_REQUIRED,
                [
                    'accepted_feature_flags_contract_versions' => $result->acceptedFeatureFlagsContractVersions(),
                ]
            );
        }

        return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
    }
}
