<?php

namespace App\Auth;

use Doctrine\DBAL\Connection;

final class AuthMcpChallengeRepository implements AuthMcpChallengeRepositoryInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function findByChallengeId(string $challengeId): ?AuthMcpChallenge
    {
        $row = $this->connection->fetchAssociative(
            'SELECT challenge_id, client_id, openpgp_fingerprint, challenge, expires_at, used, used_at
             FROM auth_mcp_challenge WHERE challenge_id = :challengeId LIMIT 1',
            ['challengeId' => $challengeId]
        );

        return is_array($row) ? AuthMcpChallenge::fromArray($row) : null;
    }

    public function findAll(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT challenge_id, client_id, openpgp_fingerprint, challenge, expires_at, used, used_at FROM auth_mcp_challenge'
        );
        $challenges = [];
        foreach ($rows as $row) {
            $challenge = AuthMcpChallenge::fromArray($row);
            if ($challenge !== null) {
                $challenges[] = $challenge;
            }
        }

        return $challenges;
    }

    public function save(AuthMcpChallenge $challenge): void
    {
        $data = $challenge->toRow();
        if ($this->findByChallengeId($challenge->challengeId) !== null) {
            $this->connection->update('auth_mcp_challenge', $data, ['challenge_id' => $challenge->challengeId]);
            return;
        }

        $this->connection->insert('auth_mcp_challenge', $data);
    }

    public function delete(string $challengeId): void
    {
        $this->connection->delete('auth_mcp_challenge', ['challenge_id' => trim($challengeId)]);
    }
}
