<?php

namespace App\User;

use Doctrine\DBAL\Connection;

final class UserTwoFactorStateRepository implements UserTwoFactorStateRepositoryInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function findByUserId(string $userId): ?UserTwoFactorState
    {
        $row = $this->connection->fetchAssociative(
            'SELECT user_id, enabled, pending_secret_encrypted, secret_encrypted, recovery_code_hashes, legacy_recovery_code_sha256, created_at, updated_at
             FROM user_two_factor_state
             WHERE user_id = :userId
             LIMIT 1',
            ['userId' => $userId]
        );

        return is_array($row) ? UserTwoFactorState::fromArray($row) : null;
    }

    public function save(UserTwoFactorState $state): void
    {
        $data = $state->toRow();

        if ($this->findByUserId($state->userId) !== null) {
            $this->connection->update('user_two_factor_state', $data, ['user_id' => $state->userId]);
            return;
        }

        $this->connection->insert('user_two_factor_state', $data);
    }

    public function delete(string $userId): void
    {
        $userId = trim($userId);
        if ($userId === '') {
            return;
        }

        $this->connection->delete('user_two_factor_state', ['user_id' => $userId]);
    }
}
