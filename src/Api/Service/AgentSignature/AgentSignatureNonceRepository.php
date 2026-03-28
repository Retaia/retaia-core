<?php

namespace App\Api\Service\AgentSignature;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class AgentSignatureNonceRepository implements AgentSignatureNonceRepositoryInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function consume(string $agentId, string $nonce, int $ttlSeconds): bool
    {
        $now = time();
        $record = AgentSignatureNonceRecord::create($agentId, $nonce, $ttlSeconds, $now);
        if ($record === null) {
            return false;
        }

        $this->connection->executeStatement('DELETE FROM agent_signature_nonce WHERE expires_at <= :now', ['now' => $now]);

        try {
            $this->connection->insert('agent_signature_nonce', $record->toRow());
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
