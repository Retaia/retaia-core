<?php

namespace App\Controller\Api;

use App\Application\AuthClient\AuthClientAdminEndpointsHandler;
use App\Application\AuthClient\AuthClientDeviceFlowEndpointsHandler;
use App\Application\AuthClient\MintClientTokenEndpointHandler;
use App\Controller\RequestPayloadTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/auth')]
final class AuthClientController
{
    use RequestPayloadTrait;

    public function __construct(
        private AuthClientHttpResponder $clientResponder,
        private MintClientTokenEndpointHandler $mintClientTokenEndpointHandler,
        private AuthClientAdminEndpointsHandler $authClientAdminEndpointsHandler,
        private AuthClientDeviceFlowEndpointsHandler $authClientDeviceFlowEndpointsHandler,
    ) {
    }

    #[Route('/clients/token', name: 'api_auth_clients_token', methods: ['POST'])]
    public function clientToken(Request $request): JsonResponse
    {
        return $this->clientResponder->clientToken($this->mintClientTokenEndpointHandler->handle(
            $this->payload($request),
            (string) ($request->getClientIp() ?? 'unknown')
        ));
    }

    #[Route('/clients/{clientId}/revoke-token', name: 'api_auth_clients_revoke_token', methods: ['POST'])]
    public function revokeClientToken(string $clientId): JsonResponse
    {
        return $this->clientResponder->revokeClientToken($clientId, $this->authClientAdminEndpointsHandler->revoke($clientId));
    }

    #[Route('/clients/{clientId}/rotate-secret', name: 'api_auth_clients_rotate_secret', methods: ['POST'])]
    public function rotateClientSecret(string $clientId): JsonResponse
    {
        return $this->clientResponder->rotateClientSecret($clientId, $this->authClientAdminEndpointsHandler->rotate($clientId));
    }

    #[Route('/clients/device/start', name: 'api_auth_clients_device_start', methods: ['POST'])]
    public function startDeviceFlow(Request $request): JsonResponse
    {
        return $this->clientResponder->startDeviceFlow($this->authClientDeviceFlowEndpointsHandler->start(
            $this->payload($request),
            (string) ($request->getClientIp() ?? 'unknown')
        ));
    }

    #[Route('/clients/device/poll', name: 'api_auth_clients_device_poll', methods: ['POST'])]
    public function pollDeviceFlow(Request $request): JsonResponse
    {
        return $this->clientResponder->pollDeviceFlow(
            $this->authClientDeviceFlowEndpointsHandler->poll($this->payload($request))
        );
    }

    #[Route('/clients/device/cancel', name: 'api_auth_clients_device_cancel', methods: ['POST'])]
    public function cancelDeviceFlow(Request $request): JsonResponse
    {
        return $this->clientResponder->cancelDeviceFlow(
            $this->authClientDeviceFlowEndpointsHandler->cancel($this->payload($request))
        );
    }
}
