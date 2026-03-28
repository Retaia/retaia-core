<?php

namespace App\Auth;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class UserAccessTokenService
{
    private Configuration $jwt;

    public function __construct(
        private Connection $connection,
        #[Autowire('%kernel.secret%')]
        string $secret,
        #[Autowire('%app.user_token_ttl_seconds%')]
        private int $ttlSeconds,
        #[Autowire('%app.user_refresh_token_ttl_seconds%')]
        private int $refreshTtlSeconds = 2592000,
    ) {
        $keyMaterial = hash('sha256', $secret);
        $this->jwt = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($keyMaterial)
        );
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, client_id: string, client_kind: string}
     */
    public function issue(User $user, string $clientId, string $clientKind): array
    {
        return $this->issueForPrincipal($user->getId(), $user->getEmail(), $clientId, $clientKind);
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, client_id: string, client_kind: string}|null
     */
    public function refresh(string $refreshToken, ?string $clientId = null, ?string $clientKind = null): ?array
    {
        $normalizedRefreshToken = trim($refreshToken);
        if ($normalizedRefreshToken === '') {
            return null;
        }

        $session = $this->sessionByRefreshToken($normalizedRefreshToken);
        if (!is_array($session)) {
            return null;
        }

        $activeClientId = (string) ($session['client_id'] ?? '');
        $activeClientKind = (string) ($session['client_kind'] ?? '');

        if ($clientId !== null && $clientId !== '' && !hash_equals($activeClientId, $clientId)) {
            return null;
        }

        if ($clientKind !== null && $clientKind !== '' && !hash_equals($activeClientKind, $clientKind)) {
            return null;
        }

        $userId = (string) ($session['user_id'] ?? '');
        $email = (string) ($session['email'] ?? '');
        if ($userId === '' || $email === '' || $activeClientId === '' || $activeClientKind === '') {
            return null;
        }

        return $this->issueForPrincipal($userId, $email, $activeClientId, $activeClientKind, $session);
    }

    /**
     * @return array{user_id: string, email: string, client_id: string, client_kind: string, session_id: string}|null
     */
    public function validate(string $rawToken): ?array
    {
        $token = $this->parseToken($rawToken);
        if (!$token instanceof UnencryptedToken) {
            return null;
        }

        if (!$this->jwt->validator()->validate(
            $token,
            new SignedWith($this->jwt->signer(), $this->jwt->verificationKey()),
            new IssuedBy('retaia-core')
        )) {
            return null;
        }

        $expiresAt = $token->claims()->get('exp');
        if (!$expiresAt instanceof \DateTimeImmutable || $expiresAt <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            return null;
        }

        $userId = (string) $token->claims()->get('sub', '');
        $email = (string) $token->claims()->get('email', '');
        $sessionId = (string) $token->claims()->get('session_id', '');
        $clientId = (string) $token->claims()->get('client_id', '');
        $clientKind = (string) $token->claims()->get('client_kind', '');

        if ($userId === '' || $email === '' || $sessionId === '' || $clientId === '' || $clientKind === '') {
            return null;
        }

        $active = $this->sessionBySessionId($sessionId);
        if (!is_array($active)) {
            return null;
        }

        if (!hash_equals((string) ($active['access_token'] ?? ''), $rawToken)) {
            return null;
        }

        $active['last_used_at'] = time();
        $this->persistSession($active);

        return [
            'user_id' => $userId,
            'email' => $email,
            'client_id' => $clientId,
            'client_kind' => $clientKind,
            'session_id' => $sessionId,
        ];
    }

    public function revoke(string $rawToken): bool
    {
        $token = $this->parseToken($rawToken);
        if (!$token instanceof UnencryptedToken) {
            return false;
        }

        $sessionId = (string) $token->claims()->get('session_id', '');
        if ($sessionId === '') {
            return false;
        }

        $active = $this->sessionBySessionId($sessionId);
        if (!is_array($active)) {
            return false;
        }

        if (!hash_equals((string) ($active['access_token'] ?? ''), $rawToken)) {
            return false;
        }

        $this->deleteSession($sessionId);

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sessionsForUser(string $userId, string $currentSessionId): array
    {
        $items = [];
        foreach ($this->sessionsByUserId($userId) as $session) {
            if (!is_array($session) || !hash_equals((string) ($session['user_id'] ?? ''), $userId)) {
                continue;
            }

            $sessionId = trim((string) ($session['session_id'] ?? ''));
            if ($sessionId === '') {
                continue;
            }

            $items[] = [
                'session_id' => $sessionId,
                'client_id' => (string) ($session['client_id'] ?? ''),
                'created_at' => $this->formatTimestamp((int) ($session['created_at'] ?? 0)),
                'last_used_at' => $this->formatTimestamp((int) ($session['last_used_at'] ?? 0)),
                'expires_at' => $this->formatTimestamp((int) ($session['refresh_expires_at'] ?? 0)),
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
        $active = $this->sessionBySessionId($sessionId);
        if (!is_array($active) || !hash_equals((string) ($active['user_id'] ?? ''), $userId)) {
            return 'NOT_FOUND';
        }

        if (hash_equals($sessionId, $currentSessionId)) {
            return 'CURRENT_SESSION';
        }

        $this->deleteSession($sessionId);

        return 'REVOKED';
    }

    public function revokeOtherSessions(string $userId, string $currentSessionId): int
    {
        $revoked = 0;

        foreach ($this->sessionsByUserId($userId) as $session) {
            $sessionId = (string) ($session['session_id'] ?? '');
            if (
                !is_array($session)
                || $sessionId === ''
                || !hash_equals((string) ($session['user_id'] ?? ''), $userId)
                || hash_equals((string) $sessionId, $currentSessionId)
            ) {
                continue;
            }

            $this->deleteSession($sessionId);
            ++$revoked;
        }

        return $revoked;
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, client_id: string, client_kind: string}
     */
    private function issueForPrincipal(string $userId, string $email, string $clientId, string $clientKind, ?array $session = null): array
    {
        $issuedAt = time();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimestamp($issuedAt);
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->ttlSeconds));
        $refreshExpiresAt = $now->modify(sprintf('+%d seconds', $this->refreshTtlSeconds));
        $refreshToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $sessionId = trim((string) ($session['session_id'] ?? ''));
        if ($sessionId === '') {
            $sessionId = bin2hex(random_bytes(16));
        }
        $createdAt = (int) ($session['created_at'] ?? $issuedAt);

        $token = $this->jwt->builder()
            ->issuedBy('retaia-core')
            ->issuedAt($now)
            ->expiresAt($expiresAt)
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->relatedTo($userId)
            ->withClaim('email', $email)
            ->withClaim('session_id', $sessionId)
            ->withClaim('client_id', $clientId)
            ->withClaim('client_kind', $clientKind)
            ->withClaim('actor_kind', 'USER_INTERACTIVE')
            ->getToken($this->jwt->signer(), $this->jwt->signingKey())
            ->toString();

        $this->persistSession([
            'session_id' => $sessionId,
            'access_token' => $token,
            'refresh_token' => $refreshToken,
            'access_expires_at' => $expiresAt->getTimestamp(),
            'refresh_expires_at' => $refreshExpiresAt->getTimestamp(),
            'user_id' => $userId,
            'email' => $email,
            'client_id' => $clientId,
            'client_kind' => $clientKind,
            'created_at' => $createdAt,
            'last_used_at' => $issuedAt,
        ]);

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $this->ttlSeconds,
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_kind' => $clientKind,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function sessionByRefreshToken(string $refreshToken): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT session_id, access_token, refresh_token, access_expires_at, refresh_expires_at, user_id, email, client_id, client_kind, created_at, last_used_at
             FROM user_auth_session
             WHERE refresh_token = :refreshToken
             LIMIT 1',
            ['refreshToken' => $refreshToken]
        );

        if (!is_array($row)) {
            return null;
        }

        $normalized = $this->normalizeStoredSession($row);
        if ($normalized === null) {
            $this->deleteSession((string) ($row['session_id'] ?? ''));
            return null;
        }

        if ((int) ($normalized['refresh_expires_at'] ?? 0) <= time()) {
            $this->deleteSession((string) ($normalized['session_id'] ?? ''));
            return null;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sessionBySessionId(string $sessionId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT session_id, access_token, refresh_token, access_expires_at, refresh_expires_at, user_id, email, client_id, client_kind, created_at, last_used_at
             FROM user_auth_session
             WHERE session_id = :sessionId
             LIMIT 1',
            ['sessionId' => $sessionId]
        );

        if (!is_array($row)) {
            return null;
        }

        $normalized = $this->normalizeStoredSession($row);
        if ($normalized === null) {
            $this->deleteSession($sessionId);
            return null;
        }

        if ((int) ($normalized['refresh_expires_at'] ?? 0) <= time()) {
            $this->deleteSession($sessionId);
            return null;
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sessionsByUserId(string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT session_id, access_token, refresh_token, access_expires_at, refresh_expires_at, user_id, email, client_id, client_kind, created_at, last_used_at
             FROM user_auth_session
             WHERE user_id = :userId',
            ['userId' => $userId]
        );

        $sessions = [];
        foreach ($rows as $row) {
            $normalized = $this->normalizeStoredSession($row);
            if ($normalized === null) {
                $this->deleteSession((string) ($row['session_id'] ?? ''));
                continue;
            }

            if ((int) ($normalized['refresh_expires_at'] ?? 0) <= time()) {
                $this->deleteSession((string) ($normalized['session_id'] ?? ''));
                continue;
            }

            $sessions[] = $normalized;
        }

        return $sessions;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function persistSession(array $session): void
    {
        $normalized = $this->normalizeStoredSession($session);
        if ($normalized === null) {
            return;
        }

        $data = [
            'session_id' => (string) $normalized['session_id'],
            'access_token' => (string) $normalized['access_token'],
            'refresh_token' => (string) $normalized['refresh_token'],
            'access_expires_at' => (int) $normalized['access_expires_at'],
            'refresh_expires_at' => (int) $normalized['refresh_expires_at'],
            'user_id' => (string) $normalized['user_id'],
            'email' => (string) $normalized['email'],
            'client_id' => (string) $normalized['client_id'],
            'client_kind' => (string) $normalized['client_kind'],
            'created_at' => (int) $normalized['created_at'],
            'last_used_at' => (int) $normalized['last_used_at'],
        ];

        if ($this->sessionExists((string) $normalized['session_id'])) {
            $this->connection->update('user_auth_session', $data, ['session_id' => (string) $normalized['session_id']]);
            return;
        }

        $this->connection->insert('user_auth_session', $data);
    }

    private function deleteSession(string $sessionId): void
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }

        $this->connection->delete('user_auth_session', ['session_id' => $sessionId]);
    }

    private function sessionExists(string $sessionId): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_auth_session WHERE session_id = :sessionId',
            ['sessionId' => $sessionId]
        ) > 0;
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>|null
     */
    private function normalizeStoredSession(array $session): ?array
    {
        $userId = trim((string) ($session['user_id'] ?? ''));
        $email = trim((string) ($session['email'] ?? ''));
        $clientId = trim((string) ($session['client_id'] ?? ''));
        $clientKind = trim((string) ($session['client_kind'] ?? ''));
        $accessToken = trim((string) ($session['access_token'] ?? ''));
        $refreshToken = trim((string) ($session['refresh_token'] ?? ''));
        if ($userId === '' || $email === '' || $clientId === '' || $clientKind === '' || $accessToken === '' || $refreshToken === '') {
            return null;
        }

        $sessionId = trim((string) ($session['session_id'] ?? ''));
        if ($sessionId === '' || str_contains($sessionId, '|')) {
            return null;
        }

        $createdAt = (int) ($session['created_at'] ?? ($session['issued_at'] ?? time()));
        $lastUsedAt = (int) ($session['last_used_at'] ?? ($session['issued_at'] ?? $createdAt));
        $accessExpiresAt = (int) ($session['access_expires_at'] ?? 0);
        $refreshExpiresAt = (int) ($session['refresh_expires_at'] ?? 0);
        if ($refreshExpiresAt <= 0) {
            return null;
        }

        return [
            'session_id' => $sessionId,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'access_expires_at' => $accessExpiresAt,
            'refresh_expires_at' => $refreshExpiresAt,
            'user_id' => $userId,
            'email' => $email,
            'client_id' => $clientId,
            'client_kind' => $clientKind,
            'created_at' => $createdAt,
            'last_used_at' => $lastUsedAt,
        ];
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

    private function parseToken(string $rawToken): ?UnencryptedToken
    {
        try {
            $token = $this->jwt->parser()->parse($rawToken);
        } catch (\Throwable) {
            return null;
        }

        return $token instanceof UnencryptedToken ? $token : null;
    }
}
