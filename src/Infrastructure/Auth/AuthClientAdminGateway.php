<?php

namespace App\Infrastructure\Auth;

use App\Application\AuthClient\Port\AuthClientGateway as AuthClientGatewayPort;
use App\Auth\AuthClientAdminService;
use App\Auth\AuthClientPolicyService;

final class AuthClientAdminGateway implements AuthClientGatewayPort
{
    public function __construct(
        private AuthClientAdminService $adminService,
        private AuthClientPolicyService $policyService,
    ) {
    }

    public function isMcpDisabledByAppPolicy(): bool
    {
        return $this->policyService->isMcpDisabledByAppPolicy();
    }

    /**
     * @return array{access_token: string, token_type: string, client_id: string, client_kind: string}|null
     */
    public function mintToken(string $clientId, string $clientKind, string $secretKey): ?array
    {
        return $this->adminService->mintToken($clientId, $clientKind, $secretKey);
    }

    public function hasClient(string $clientId): bool
    {
        return $this->adminService->hasClient($clientId);
    }

    public function clientKind(string $clientId): ?string
    {
        return $this->adminService->clientKind($clientId);
    }

    public function revokeToken(string $clientId): bool
    {
        return $this->adminService->revokeToken($clientId);
    }

    public function rotateSecret(string $clientId): ?string
    {
        return $this->adminService->rotateSecret($clientId);
    }
}
