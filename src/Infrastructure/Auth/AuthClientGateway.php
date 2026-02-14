<?php

namespace App\Infrastructure\Auth;

use App\Application\AuthClient\Port\AuthClientGateway as AuthClientGatewayPort;
use App\Auth\AuthClientService;

final class AuthClientGateway implements AuthClientGatewayPort
{
    public function __construct(
        private AuthClientService $authClientService,
    ) {
    }

    public function isMcpDisabledByAppPolicy(): bool
    {
        return $this->authClientService->isMcpDisabledByAppPolicy();
    }

    /**
     * @return array{access_token: string, token_type: string, client_id: string, client_kind: string}|null
     */
    public function mintToken(string $clientId, string $clientKind, string $secretKey): ?array
    {
        return $this->authClientService->mintToken($clientId, $clientKind, $secretKey);
    }
}
