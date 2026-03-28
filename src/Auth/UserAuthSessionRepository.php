<?php

namespace App\Auth;

use Doctrine\DBAL\Connection;

final class UserAuthSessionRepository implements UserAuthSessionRepositoryInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function findByRefreshToken(string $refreshToken): ?UserAuthSession
    {
        $row = $this->connection->fetchAssociative(
            'SELECT session_id, access_token, refresh_token, access_expires_at, refresh_expires_at, user_id, email, client_id, client_kind, created_at, last_used_at
             FROM user_auth_session
             WHERE refresh_token = :refreshToken
             LIMIT 1',
            ['refreshToken' => $refreshToken]
        );

        return is_array($row) ? UserAuthSession::fromArray($row) : null;
    }

    public function findBySessionId(string $sessionId): ?UserAuthSession
    {
        $row = $this->connection->fetchAssociative(
            'SELECT session_id, access_token, refresh_token, access_expires_at, refresh_expires_at, user_id, email, client_id, client_kind, created_at, last_used_at
             FROM user_auth_session
             WHERE session_id = :sessionId
             LIMIT 1',
            ['sessionId' => $sessionId]
        );

        return is_array($row) ? UserAuthSession::fromArray($row) : null;
    }

    public function findByUserId(string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT session_id, access_token, refresh_token, access_expires_at, refresh_expires_at, user_id, email, client_id, client_kind, created_at, last_used_at
             FROM user_auth_session
             WHERE user_id = :userId',
            ['userId' => $userId]
        );

        $sessions = [];
        foreach ($rows as $row) {
            $session = UserAuthSession::fromArray($row);
            if ($session !== null) {
                $sessions[] = $session;
            }
        }

        return $sessions;
    }

    public function save(UserAuthSession $session): void
    {
        $data = $session->toRow();
        if ($this->findBySessionId($session->sessionId) !== null) {
            $this->connection->update('user_auth_session', $data, ['session_id' => $session->sessionId]);
            return;
        }

        $this->connection->insert('user_auth_session', $data);
    }

    public function delete(string $sessionId): void
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }

        $this->connection->delete('user_auth_session', ['session_id' => $sessionId]);
    }
}
