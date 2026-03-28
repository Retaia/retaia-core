<?php

namespace App\Auth;

use App\Domain\AuthClient\ClientKind;
use Doctrine\DBAL\Connection;

final class AuthClientRegistryRepository implements AuthClientRegistryRepositoryInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function findByClientId(string $clientId): ?AuthClientRegistryEntry
    {
        $this->ensureDefaults();

        $row = $this->connection->fetchAssociative(
            'SELECT client_id, client_kind, secret_key, client_label, openpgp_public_key, openpgp_fingerprint, registered_at, rotated_at
             FROM auth_client_registry
             WHERE client_id = :clientId
             LIMIT 1',
            ['clientId' => $clientId]
        );

        return is_array($row) ? AuthClientRegistryEntry::fromArray($row) : null;
    }

    public function findAll(): array
    {
        $this->ensureDefaults();
        $rows = $this->connection->fetchAllAssociative(
            'SELECT client_id, client_kind, secret_key, client_label, openpgp_public_key, openpgp_fingerprint, registered_at, rotated_at
             FROM auth_client_registry'
        );

        $entries = [];
        foreach ($rows as $row) {
            $entry = AuthClientRegistryEntry::fromArray($row);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function save(AuthClientRegistryEntry $entry): void
    {
        $data = $entry->toRow();
        if ($this->findByClientId($entry->clientId) !== null) {
            $this->connection->update('auth_client_registry', $data, ['client_id' => $entry->clientId]);
            return;
        }

        $this->connection->insert('auth_client_registry', $data);
    }

    private function ensureDefaults(): void
    {
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM auth_client_registry');
        if ($count > 0) {
            return;
        }

        $this->connection->insert('auth_client_registry', [
            'client_id' => 'agent-default',
            'client_kind' => ClientKind::AGENT,
            'secret_key' => 'agent-secret',
            'client_label' => null,
            'openpgp_public_key' => null,
            'openpgp_fingerprint' => null,
            'registered_at' => null,
            'rotated_at' => null,
        ]);
        $this->connection->insert('auth_client_registry', [
            'client_id' => 'mcp-default',
            'client_kind' => ClientKind::MCP,
            'secret_key' => 'mcp-secret',
            'client_label' => null,
            'openpgp_public_key' => null,
            'openpgp_fingerprint' => null,
            'registered_at' => null,
            'rotated_at' => null,
        ]);
    }
}
