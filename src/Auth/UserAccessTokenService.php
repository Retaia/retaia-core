<?php

namespace App\Auth;

use App\Entity\User;
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
        private UserAuthSessionRepositoryInterface $sessions,
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
        if (!$session instanceof UserAuthSession) {
            return null;
        }

        $activeClientId = $session->clientId;
        $activeClientKind = $session->clientKind;

        if ($clientId !== null && $clientId !== '' && !hash_equals($activeClientId, $clientId)) {
            return null;
        }

        if ($clientKind !== null && $clientKind !== '' && !hash_equals($activeClientKind, $clientKind)) {
            return null;
        }

        $userId = $session->userId;
        $email = $session->email;
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
        if (!$active instanceof UserAuthSession) {
            return null;
        }

        if (!hash_equals($active->accessToken, $rawToken)) {
            return null;
        }

        $this->persistSession($active->withLastUsedAt(time()));

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
        if (!$active instanceof UserAuthSession) {
            return false;
        }

        if (!hash_equals($active->accessToken, $rawToken)) {
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
        $active = $this->sessionBySessionId($sessionId);
        if (!$active instanceof UserAuthSession || !hash_equals($active->userId, $userId)) {
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
            $sessionId = $session->sessionId;
            if (
                !$session instanceof UserAuthSession
                || $sessionId === ''
                || !hash_equals($session->userId, $userId)
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
    private function issueForPrincipal(string $userId, string $email, string $clientId, string $clientKind, ?UserAuthSession $session = null): array
    {
        $issuedAt = time();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimestamp($issuedAt);
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->ttlSeconds));
        $refreshExpiresAt = $now->modify(sprintf('+%d seconds', $this->refreshTtlSeconds));
        $refreshToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $sessionId = trim((string) ($session?->sessionId ?? ''));
        if ($sessionId === '') {
            $sessionId = bin2hex(random_bytes(16));
        }
        $createdAt = (int) ($session?->createdAt ?? $issuedAt);

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

        $this->persistSession(new UserAuthSession(
            $sessionId,
            $token,
            $refreshToken,
            $expiresAt->getTimestamp(),
            $refreshExpiresAt->getTimestamp(),
            $userId,
            $email,
            $clientId,
            $clientKind,
            $createdAt,
            $issuedAt,
        ));

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $this->ttlSeconds,
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_kind' => $clientKind,
        ];
    }

    private function sessionByRefreshToken(string $refreshToken): ?UserAuthSession
    {
        $session = $this->sessions->findByRefreshToken($refreshToken);
        if (!$session instanceof UserAuthSession) {
            return null;
        }

        if ($session->refreshExpiresAt <= time()) {
            $this->deleteSession($session->sessionId);
            return null;
        }

        return $session;
    }

    private function sessionBySessionId(string $sessionId): ?UserAuthSession
    {
        $session = $this->sessions->findBySessionId($sessionId);
        if (!$session instanceof UserAuthSession) {
            return null;
        }

        if ($session->refreshExpiresAt <= time()) {
            $this->deleteSession($sessionId);
            return null;
        }

        return $session;
    }

    /**
     * @return list<UserAuthSession>
     */
    private function sessionsByUserId(string $userId): array
    {
        $sessions = [];
        foreach ($this->sessions->findByUserId($userId) as $session) {
            if ($session->refreshExpiresAt <= time()) {
                $this->deleteSession($session->sessionId);
                continue;
            }

            $sessions[] = $session;
        }

        return $sessions;
    }

    private function persistSession(UserAuthSession $session): void
    {
        $this->sessions->save($session);
    }

    private function deleteSession(string $sessionId): void
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }

        $this->sessions->delete($sessionId);
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
