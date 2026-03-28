<?php

namespace App\Auth;

use Doctrine\DBAL\Connection;

final class TechnicalAccessTokenRepository implements TechnicalAccessTokenRepositoryInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function findByClientId(string $clientId): ?TechnicalAccessTokenRecord
    {
        $row = $this->connection->fetchAssociative(
            'SELECT client_id, access_token, client_kind, issued_at FROM auth_client_access_token WHERE client_id = :clientId LIMIT 1',
            ['clientId' => $clientId]
        );

        return is_array($row) ? TechnicalAccessTokenRecord::fromArray($row) : null;
    }

    public function findByAccessToken(string $accessToken): ?TechnicalAccessTokenRecord
    {
        $row = $this->connection->fetchAssociative(
            'SELECT client_id, access_token, client_kind, issued_at FROM auth_client_access_token WHERE access_token = :accessToken LIMIT 1',
            ['accessToken' => $accessToken]
        );

        return is_array($row) ? TechnicalAccessTokenRecord::fromArray($row) : null;
    }

    public function save(TechnicalAccessTokenRecord $record): void
    {
        $data = $record->toRow();
        if ($this->findByClientId($record->clientId) !== null) {
            $this->connection->update('auth_client_access_token', $data, ['client_id' => $record->clientId]);
            return;
        }

        $this->connection->insert('auth_client_access_token', $data);
    }

    public function deleteByClientId(string $clientId): void
    {
        $this->connection->delete('auth_client_access_token', ['client_id' => trim($clientId)]);
    }
}
