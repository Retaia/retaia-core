<?php

namespace App\Api\Service\AgentSignature;

use Doctrine\DBAL\Connection;

final class AgentPublicKeyRepository implements AgentPublicKeyRepositoryInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function findByAgentId(string $agentId): ?AgentPublicKeyRecord
    {
        $row = $this->connection->fetchAssociative(
            'SELECT agent_id, openpgp_fingerprint, openpgp_public_key, updated_at
             FROM agent_public_key
             WHERE agent_id = :agentId
             LIMIT 1',
            ['agentId' => trim($agentId)]
        );

        return is_array($row) ? AgentPublicKeyRecord::fromArray($row) : null;
    }

    public function findByAgentIdAndFingerprint(string $agentId, string $fingerprint): ?AgentPublicKeyRecord
    {
        $record = $this->findByAgentId($agentId);
        if ($record === null) {
            return null;
        }

        $normalized = strtoupper(preg_replace('/\s+/', '', trim($fingerprint)) ?? '');

        return hash_equals($record->fingerprint, $normalized) ? $record : null;
    }

    public function save(AgentPublicKeyRecord $record): void
    {
        $data = $record->toRow();
        if ($this->findByAgentId($record->agentId) !== null) {
            $this->connection->update('agent_public_key', $data, ['agent_id' => $record->agentId]);

            return;
        }

        $this->connection->insert('agent_public_key', $data);
    }
}
