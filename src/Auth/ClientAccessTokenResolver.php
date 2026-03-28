<?php

namespace App\Auth;

final class ClientAccessTokenResolver
{
    public function __construct(
        private TechnicalAccessTokenRepositoryInterface $accessTokenRepository,
    ) {
    }

    /**
     * @return array{client_id: string, client_kind: string}|null
     */
    public function resolve(string $accessToken): ?array
    {
        $record = $this->accessTokenRepository->findByAccessToken($accessToken);
        if (!$record instanceof TechnicalAccessTokenRecord || $record->clientKind === '') {
            return null;
        }

        return [
            'client_id' => $record->clientId,
            'client_kind' => $record->clientKind,
        ];
    }
}
