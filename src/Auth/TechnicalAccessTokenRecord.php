<?php

namespace App\Auth;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'auth_client_access_token')]
#[ORM\UniqueConstraint(name: 'uniq_auth_client_access_token_token', columns: ['access_token'])]
final class TechnicalAccessTokenRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'client_id', type: 'string', length: 64)]
        public string $clientId,
        #[ORM\Column(name: 'access_token', type: 'text')]
        public string $accessToken,
        #[ORM\Column(name: 'client_kind', type: 'string', length: 32)]
        public string $clientKind,
        #[ORM\Column(name: 'issued_at', type: 'bigint')]
        public int $issuedAt,
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

    public function syncFrom(self $record): void
    {
        $this->accessToken = $record->accessToken;
        $this->clientKind = $record->clientKind;
        $this->issuedAt = $record->issuedAt;
    }
}
