<?php

namespace App\Controller\Api;

use App\Application\Auth\AuthSelfServiceEndpointsHandler;
use App\Controller\RequestPayloadTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/auth')]
final class AuthProfileController
{
    use RequestPayloadTrait;

    public function __construct(
        private AuthSelfServiceEndpointsHandler $authSelfServiceEndpointsHandler,
        private AuthSelfServiceHttpResponder $selfServiceResponder,
    ) {
    }

    #[Route('/me/features', name: 'api_auth_me_features_get', methods: ['GET'])]
    public function meFeatures(): JsonResponse
    {
        return $this->selfServiceResponder->meFeatures($this->authSelfServiceEndpointsHandler->getMyFeatures());
    }

    #[Route('/me/features', name: 'api_auth_me_features_patch', methods: ['PATCH'])]
    public function patchMeFeatures(Request $request): JsonResponse
    {
        return $this->selfServiceResponder->meFeatures(
            $this->authSelfServiceEndpointsHandler->patchMyFeatures($this->payload($request))
        );
    }
}
