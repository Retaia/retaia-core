<?php

namespace App\Auth;

use App\Domain\AuthClient\ClientKind;

final class AuthClientProvisioningService
{
    public function __construct(
        private AuthClientRegistryRepositoryInterface $registryRepository,
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

        $this->registryRepository->save(new AuthClientRegistryEntry(
            $clientId,
            $clientKind,
            $secretKey,
            null,
            null,
            null,
            null,
            null,
        ));

        return [
            'client_id' => $clientId,
            'secret_key' => $secretKey,
        ];
    }
}
