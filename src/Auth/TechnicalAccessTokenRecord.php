<?php

namespace App\Auth;

final class TechnicalAccessTokenRecord
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $accessToken,
        public readonly string $clientKind,
        public readonly int $issuedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        $clientId = trim((string) ($row['client_id'] ?? ''));
        $accessToken = trim((string) ($row['access_token'] ?? ''));
        $clientKind = trim((string) ($row['client_kind'] ?? ''));
        if ($clientId === '' || $accessToken === '' || $clientKind === '') {
            return null;
        }

        return new self($clientId, $accessToken, $clientKind, (int) ($row['issued_at'] ?? time()));
    }

    /**
     * @return array<string, scalar>
     */
    public function toRow(): array
    {
        return [
            'client_id' => $this->clientId,
            'access_token' => $this->accessToken,
            'client_kind' => $this->clientKind,
            'issued_at' => $this->issuedAt,
        ];
    }
}
