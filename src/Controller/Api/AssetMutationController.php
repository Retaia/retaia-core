<?php

namespace App\Controller\Api;

use App\Api\Service\AssetRequestPreconditionService;
use App\Application\Asset\AssetEndpointsHandler;
use App\Controller\RequestPayloadTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/assets')]
final class AssetMutationController
{
    use RequestPayloadTrait;

    public function __construct(
        private AssetEndpointsHandler $assetEndpointsHandler,
        private AssetRequestPreconditionService $assetPreconditions,
        private AssetHttpResponder $responder,
    ) {
    }

    #[Route('/{uuid}', name: 'api_assets_patch', methods: ['PATCH'])]
    public function patch(string $uuid, Request $request): JsonResponse
    {
        if ($this->assetEndpointsHandler->isForbiddenAgentActor()) {
            return $this->responder->forbiddenActor();
        }

        $preconditionViolation = $this->assetPreconditions->violationResponse($request, $uuid);
        if ($preconditionViolation instanceof JsonResponse) {
            return $preconditionViolation;
        }

        return $this->responder->patchResult($this->assetEndpointsHandler->patch($uuid, $this->payload($request)));
    }
}
