<?php

namespace App\Controller\Api;

use App\Api\Service\AssetRequestPreconditionService;
use App\Api\Service\IdempotencyService;
use App\Application\Asset\AssetEndpointsHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/assets')]
final class AssetWorkflowController
{
    public function __construct(
        private AssetEndpointsHandler $assetEndpointsHandler,
        private AssetRequestPreconditionService $assetPreconditions,
        private AssetHttpResponder $responder,
        private IdempotencyService $idempotency,
    ) {
    }

    #[Route('/{uuid}/reopen', name: 'api_assets_reopen', methods: ['POST'])]
    public function reopen(string $uuid, Request $request): JsonResponse
    {
        if ($this->assetEndpointsHandler->isForbiddenAgentActor()) {
            return $this->responder->forbiddenActor();
        }

        $preconditionViolation = $this->assetPreconditions->violationResponse($request, $uuid);
        if ($preconditionViolation instanceof JsonResponse) {
            return $preconditionViolation;
        }

        return $this->responder->assetActionResult($this->assetEndpointsHandler->reopen($uuid));
    }

    #[Route('/{uuid}/reprocess', name: 'api_assets_reprocess', methods: ['POST'])]
    public function reprocess(string $uuid, Request $request): JsonResponse
    {
        if ($this->assetEndpointsHandler->isForbiddenAgentActor()) {
            return $this->responder->forbiddenActor();
        }

        $preconditionViolation = $this->assetPreconditions->violationResponse($request, $uuid);
        if ($preconditionViolation instanceof JsonResponse) {
            return $preconditionViolation;
        }

        return $this->idempotency->execute($request, $this->assetEndpointsHandler->actorId(), function () use ($uuid): JsonResponse {
            return $this->responder->assetActionResult($this->assetEndpointsHandler->reprocess($uuid));
        });
    }
}
