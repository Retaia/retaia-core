<?php

namespace App\Auth;

final class UserAuthSessionService
{
    public function __construct(
        private UserAuthSessionRepositoryInterface $sessions,
    ) {
    }

    public function byRefreshToken(string $refreshToken): ?UserAuthSession
    {
        $session = $this->sessions->findByRefreshToken($refreshToken);
        if (!$session instanceof UserAuthSession) {
            return null;
        }

        if ($session->refreshExpiresAt <= time()) {
            $this->delete($session->sessionId);
            return null;
        }

        return $session;
    }

    public function bySessionId(string $sessionId): ?UserAuthSession
    {
        $session = $this->sessions->findBySessionId($sessionId);
        if (!$session instanceof UserAuthSession) {
            return null;
        }

        if ($session->refreshExpiresAt <= time()) {
            $this->delete($sessionId);
            return null;
        }

        return $session;
    }

    /**
     * @return list<UserAuthSession>
     */
    public function byUserId(string $userId): array
    {
        $sessions = [];
        foreach ($this->sessions->findByUserId($userId) as $session) {
            if ($session->refreshExpiresAt <= time()) {
                $this->delete($session->sessionId);
                continue;
            }

            $sessions[] = $session;
        }

        return $sessions;
    }

    public function save(UserAuthSession $session): void
    {
        $this->sessions->save($session);
    }

    public function delete(string $sessionId): void
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }

        $this->sessions->delete($sessionId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sessionsForUser(string $userId, string $currentSessionId): array
    {
        $items = [];
        foreach ($this->byUserId($userId) as $session) {
            if (!$session instanceof UserAuthSession || !hash_equals($session->userId, $userId)) {
                continue;
            }

            $sessionId = trim($session->sessionId);
            if ($sessionId === '') {
                continue;
            }

            $items[] = [
                'session_id' => $sessionId,
                'client_id' => $session->clientId,
                'created_at' => $this->formatTimestamp($session->createdAt),
                'last_used_at' => $this->formatTimestamp($session->lastUsedAt),
                'expires_at' => $this->formatTimestamp($session->refreshExpiresAt),
                'is_current' => hash_equals($sessionId, $currentSessionId),
                'device_label' => null,
                'browser' => null,
                'os' => null,
                'ip_address_last_seen' => null,
            ];
        }

        usort(
            $items,
            static function (array $left, array $right): int {
                if (($left['is_current'] ?? false) !== ($right['is_current'] ?? false)) {
                    return ($left['is_current'] ?? false) ? -1 : 1;
                }

                return strcmp((string) $right['last_used_at'], (string) $left['last_used_at']);
            }
        );

        return $items;
    }

    public function revokeSession(string $userId, string $sessionId, string $currentSessionId): string
    {
        $active = $this->bySessionId($sessionId);
        if (!$active instanceof UserAuthSession || !hash_equals($active->userId, $userId)) {
            return 'NOT_FOUND';
        }

        if (hash_equals($sessionId, $currentSessionId)) {
            return 'CURRENT_SESSION';
        }

        $this->delete($sessionId);

        return 'REVOKED';
    }

    public function revokeOtherSessions(string $userId, string $currentSessionId): int
    {
        $revoked = 0;

        foreach ($this->byUserId($userId) as $session) {
            $sessionId = $session->sessionId;
            if (
                !$session instanceof UserAuthSession
                || $sessionId === ''
                || !hash_equals($session->userId, $userId)
                || hash_equals((string) $sessionId, $currentSessionId)
            ) {
                continue;
            }

            $this->delete($sessionId);
            ++$revoked;
        }

        return $revoked;
    }

    private function formatTimestamp(int $timestamp): ?string
    {
        if ($timestamp <= 0) {
            return null;
        }

        return (new \DateTimeImmutable('@'.$timestamp))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(\DateTimeInterface::ATOM);
    }
}
