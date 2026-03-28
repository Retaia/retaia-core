<?php

namespace App\Infrastructure\Auth;

use App\Application\Auth\Port\AuthenticatedClientGateway as AuthenticatedClientGatewayPort;
use App\Auth\ClientAccessTokenResolver;
use App\Auth\UserAccessTokenService;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuthenticatedClientGateway implements AuthenticatedClientGatewayPort
{
    public function __construct(
        private RequestStack $requestStack,
        private UserAccessTokenService $userAccessTokenService,
        private ClientAccessTokenResolver $clientAccessTokenResolver,
    ) {
    }

    public function currentClient(): ?array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }

        $authorization = trim((string) $request->headers->get('Authorization', ''));
        if (!str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $accessToken = trim(substr($authorization, 7));
        if ($accessToken === '') {
            return null;
        }

        $userPayload = $this->userAccessTokenService->validate($accessToken);
        if (is_array($userPayload)) {
            return [
                'client_id' => (string) $userPayload['client_id'],
                'client_kind' => (string) $userPayload['client_kind'],
            ];
        }

        $clientPayload = $this->clientAccessTokenResolver->resolve($accessToken);
        if (is_array($clientPayload)) {
            return [
                'client_id' => (string) $clientPayload['client_id'],
                'client_kind' => (string) $clientPayload['client_kind'],
            ];
        }

        return null;
    }
}
