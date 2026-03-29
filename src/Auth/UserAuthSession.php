<?php

namespace App\Auth;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_auth_session')]
#[ORM\UniqueConstraint(name: 'uniq_user_auth_session_refresh_token', columns: ['refresh_token'])]
#[ORM\Index(name: 'idx_user_auth_session_user_id', columns: ['user_id'])]
#[ORM\Index(name: 'idx_user_auth_session_refresh_expires_at', columns: ['refresh_expires_at'])]
final class UserAuthSession
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'session_id', type: 'string', length: 32)]
        public string $sessionId,
        #[ORM\Column(name: 'access_token', type: 'text')]
        public string $accessToken,
        #[ORM\Column(name: 'refresh_token', type: 'string', length: 255)]
        public string $refreshToken,
        #[ORM\Column(name: 'access_expires_at', type: 'bigint')]
        public int $accessExpiresAt,
        #[ORM\Column(name: 'refresh_expires_at', type: 'bigint')]
        public int $refreshExpiresAt,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        public string $userId,
        #[ORM\Column(name: 'email', type: 'string', length: 180)]
        public string $email,
        #[ORM\Column(name: 'client_id', type: 'string', length: 64)]
        public string $clientId,
        #[ORM\Column(name: 'client_kind', type: 'string', length: 32)]
        public string $clientKind,
        #[ORM\Column(name: 'created_at', type: 'bigint')]
        public int $createdAt,
        #[ORM\Column(name: 'last_used_at', type: 'bigint')]
        public int $lastUsedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        $sessionId = trim((string) ($row['session_id'] ?? ''));
        $accessToken = trim((string) ($row['access_token'] ?? ''));
        $refreshToken = trim((string) ($row['refresh_token'] ?? ''));
        $userId = trim((string) ($row['user_id'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));
        $clientId = trim((string) ($row['client_id'] ?? ''));
        $clientKind = trim((string) ($row['client_kind'] ?? ''));

        if (
            $sessionId === ''
            || str_contains($sessionId, '|')
            || $accessToken === ''
            || $refreshToken === ''
            || $userId === ''
            || $email === ''
            || $clientId === ''
            || $clientKind === ''
        ) {
            return null;
        }

        $refreshExpiresAt = (int) ($row['refresh_expires_at'] ?? 0);
        if ($refreshExpiresAt <= 0) {
            return null;
        }

        return new self(
            $sessionId,
            $accessToken,
            $refreshToken,
            (int) ($row['access_expires_at'] ?? 0),
            $refreshExpiresAt,
            $userId,
            $email,
            $clientId,
            $clientKind,
            (int) ($row['created_at'] ?? time()),
            (int) ($row['last_used_at'] ?? ($row['created_at'] ?? time())),
        );
    }

    public function withLastUsedAt(int $timestamp): self
    {
        return new self(
            $this->sessionId,
            $this->accessToken,
            $this->refreshToken,
            $this->accessExpiresAt,
            $this->refreshExpiresAt,
            $this->userId,
            $this->email,
            $this->clientId,
            $this->clientKind,
            $this->createdAt,
            $timestamp,
        );
    }

    public function syncFrom(self $session): void
    {
        $this->accessToken = $session->accessToken;
        $this->refreshToken = $session->refreshToken;
        $this->accessExpiresAt = $session->accessExpiresAt;
        $this->refreshExpiresAt = $session->refreshExpiresAt;
        $this->userId = $session->userId;
        $this->email = $session->email;
        $this->clientId = $session->clientId;
        $this->clientKind = $session->clientKind;
        $this->createdAt = $session->createdAt;
        $this->lastUsedAt = $session->lastUsedAt;
    }
}
