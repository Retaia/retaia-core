<?php

namespace App\Auth;

use App\Domain\AuthClient\ClientKind;

final class AuthClientProvisioningService
{
    public function __construct(
        private AuthClientStateStore $stateStore,
    ) {
    }

    /**
     * @return array{client_id: string, secret_key: string}|null
     */
    public function provisionClient(string $clientKind): ?array
    {
        if (!ClientKind::isTechnical($clientKind)) {
            return null;
        }

        $clientId = strtolower($clientKind).'-'.bin2hex(random_bytes(6));
        $secretKey = bin2hex(random_bytes(24));

        $registry = $this->stateStore->registry();
        $registry[$clientId] = [
            'client_kind' => $clientKind,
            'secret_key' => $secretKey,
        ];
        $this->stateStore->saveRegistry($registry);

        return [
            'client_id' => $clientId,
            'secret_key' => $secretKey,
        ];
    }
}
