<?php

namespace App\Auth;

use App\Entity\User;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class UserAccessTokenService
{
    private const TOKEN_STORE_KEY = 'auth_user_active_tokens';

    private Configuration $jwt;

    public function __construct(
        private CacheItemPoolInterface $cache,
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

        foreach ($this->activeTokens() as $session) {
            if (!is_array($session)) {
                continue;
            }

            $storedRefreshToken = (string) ($session['refresh_token'] ?? '');
            if ($storedRefreshToken === '' || !hash_equals($storedRefreshToken, $normalizedRefreshToken)) {
                continue;
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

        return null;
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

        $activeTokens = $this->activeTokens();
        $active = $activeTokens[$sessionId] ?? null;
        if (!is_array($active)) {
            return null;
        }

        if (!hash_equals((string) ($active['access_token'] ?? ''), $rawToken)) {
            return null;
        }

        $active['last_used_at'] = time();
        $activeTokens[$sessionId] = $active;
        $this->saveActiveTokens($activeTokens);

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

        $activeTokens = $this->activeTokens();
        $active = $activeTokens[$sessionId] ?? null;
        if (!is_array($active)) {
            return false;
        }

        if (!hash_equals((string) ($active['access_token'] ?? ''), $rawToken)) {
            return false;
        }

        unset($activeTokens[$sessionId]);
        $this->saveActiveTokens($activeTokens);

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sessionsForUser(string $userId, string $currentSessionId): array
    {
        $items = [];
        foreach ($this->activeTokens() as $session) {
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
        $activeTokens = $this->activeTokens();
        $active = $activeTokens[$sessionId] ?? null;
        if (!is_array($active) || !hash_equals((string) ($active['user_id'] ?? ''), $userId)) {
            return 'NOT_FOUND';
        }

        if (hash_equals($sessionId, $currentSessionId)) {
            return 'CURRENT_SESSION';
        }

        unset($activeTokens[$sessionId]);
        $this->saveActiveTokens($activeTokens);

        return 'REVOKED';
    }

    public function revokeOtherSessions(string $userId, string $currentSessionId): int
    {
        $activeTokens = $this->activeTokens();
        $revoked = 0;

        foreach ($activeTokens as $sessionId => $session) {
            if (
                !is_array($session)
                || !hash_equals((string) ($session['user_id'] ?? ''), $userId)
                || hash_equals((string) $sessionId, $currentSessionId)
            ) {
                continue;
            }

            unset($activeTokens[$sessionId]);
            ++$revoked;
        }

        $this->saveActiveTokens($activeTokens);

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

        $activeTokens = $this->activeTokens();
        $activeTokens[$sessionId] = [
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
        ];
        $this->saveActiveTokens($activeTokens);

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
    private function activeTokens(): array
    {
        $item = $this->cache->getItem(self::TOKEN_STORE_KEY);
        $value = $item->get();
        if (!is_array($value)) {
            return [];
        }

        $tokens = [];
        $changed = false;
        $now = time();

        foreach ($value as $key => $session) {
            if (!is_array($session)) {
                $changed = true;
                continue;
            }

            $normalized = $this->normalizeStoredSession((string) $key, $session);
            if ($normalized === null) {
                $changed = true;
                continue;
            }

            if ((int) ($normalized['refresh_expires_at'] ?? 0) <= $now) {
                $changed = true;
                continue;
            }

            $tokens[(string) $normalized['session_id']] = $normalized;
        }

        if ($changed) {
            $this->saveActiveTokens($tokens);
        }

        return $tokens;
    }

    /**
     * @param array<string, array<string, mixed>> $tokens
     */
    private function saveActiveTokens(array $tokens): void
    {
        $item = $this->cache->getItem(self::TOKEN_STORE_KEY);
        $item->set($tokens);
        $this->cache->save($item);
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>|null
     */
    private function normalizeStoredSession(string $index, array $session): ?array
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
        if ($sessionId === '') {
            $sessionId = $index;
        }
        if ($sessionId === '' || str_contains($sessionId, '|')) {
            $sessionId = bin2hex(random_bytes(16));
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
