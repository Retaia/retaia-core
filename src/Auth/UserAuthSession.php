<?php

namespace App\Auth;

final class UserAuthSession
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly int $accessExpiresAt,
        public readonly int $refreshExpiresAt,
        public readonly string $userId,
        public readonly string $email,
        public readonly string $clientId,
        public readonly string $clientKind,
        public readonly int $createdAt,
        public readonly int $lastUsedAt,
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

    /**
     * @return array<string, scalar>
     */
    public function toRow(): array
    {
        return [
            'session_id' => $this->sessionId,
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'access_expires_at' => $this->accessExpiresAt,
            'refresh_expires_at' => $this->refreshExpiresAt,
            'user_id' => $this->userId,
            'email' => $this->email,
            'client_id' => $this->clientId,
            'client_kind' => $this->clientKind,
            'created_at' => $this->createdAt,
            'last_used_at' => $this->lastUsedAt,
        ];
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
}
