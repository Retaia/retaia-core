<?php

namespace App\Application\AuthClient\Port;

interface AuthClientGateway
{
    public function isMcpDisabledByAppPolicy(): bool;

    /**
     * @return array{access_token: string, token_type: string, client_id: string, client_kind: string}|null
     */
    public function mintToken(string $clientId, string $clientKind, string $secretKey): ?array;

    public function hasClient(string $clientId): bool;

    public function clientKind(string $clientId): ?string;

    public function revokeToken(string $clientId): bool;

    public function rotateSecret(string $clientId): ?string;
}
