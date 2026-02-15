<?php

namespace App\Auth;

final class ClientAccessTokenResolver
{
    public function __construct(
        private AuthClientStateStore $stateStore,
    ) {
    }

    /**
     * @return array{client_id: string, client_kind: string}|null
     */
    public function resolve(string $accessToken): ?array
    {
        foreach ($this->stateStore->activeTokens() as $clientId => $tokenPayload) {
            if (!is_array($tokenPayload)) {
                continue;
            }

            if (!hash_equals((string) ($tokenPayload['access_token'] ?? ''), $accessToken)) {
                continue;
            }

            $clientKind = (string) ($tokenPayload['client_kind'] ?? '');
            if ($clientKind === '') {
                return null;
            }

            return [
                'client_id' => (string) $clientId,
                'client_kind' => $clientKind,
            ];
        }

        return null;
    }
}
