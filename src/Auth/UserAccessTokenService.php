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

        $activeTokens = $this->activeTokens();
        $now = time();

        foreach ($activeTokens as $index => $active) {
            if (!is_array($active)) {
                continue;
            }

            $storedRefreshToken = (string) ($active['refresh_token'] ?? '');
            if ($storedRefreshToken === '' || !hash_equals($storedRefreshToken, $normalizedRefreshToken)) {
                continue;
            }

            $refreshExpiresAt = (int) ($active['refresh_expires_at'] ?? 0);
            if ($refreshExpiresAt <= $now) {
                unset($activeTokens[$index]);
                $this->saveActiveTokens($activeTokens);

                return null;
            }

            $activeClientId = (string) ($active['client_id'] ?? '');
            $activeClientKind = (string) ($active['client_kind'] ?? '');

            if ($clientId !== null && $clientId !== '' && !hash_equals($activeClientId, $clientId)) {
                return null;
            }

            if ($clientKind !== null && $clientKind !== '' && !hash_equals($activeClientKind, $clientKind)) {
                return null;
            }

            $userId = (string) ($active['user_id'] ?? '');
            $email = (string) ($active['email'] ?? '');
            if ($userId === '' || $email === '' || $activeClientId === '' || $activeClientKind === '') {
                return null;
            }

            return $this->issueForPrincipal($userId, $email, $activeClientId, $activeClientKind);
        }

        return null;
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, client_id: string, client_kind: string}
     */
    private function issueForPrincipal(string $userId, string $email, string $clientId, string $clientKind): array
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimestamp(time());
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->ttlSeconds));
        $refreshExpiresAt = $now->modify(sprintf('+%d seconds', $this->refreshTtlSeconds));
        $refreshToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        $token = $this->jwt->builder()
            ->issuedBy('retaia-core')
            ->issuedAt($now)
            ->expiresAt($expiresAt)
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->relatedTo($userId)
            ->withClaim('email', $email)
            ->withClaim('client_id', $clientId)
            ->withClaim('client_kind', $clientKind)
            ->withClaim('actor_kind', 'USER_INTERACTIVE')
            ->getToken($this->jwt->signer(), $this->jwt->signingKey())
            ->toString();

        $activeTokens = $this->activeTokens();
        $activeTokens[$this->tokenIndex($userId, $clientId)] = [
            'access_token' => $token,
            'refresh_token' => $refreshToken,
            'refresh_expires_at' => $refreshExpiresAt->getTimestamp(),
            'user_id' => $userId,
            'email' => $email,
            'client_id' => $clientId,
            'client_kind' => $clientKind,
            'issued_at' => time(),
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
     * @return array{user_id: string, email: string, client_id: string, client_kind: string}|null
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
        $clientId = (string) $token->claims()->get('client_id', '');
        $clientKind = (string) $token->claims()->get('client_kind', '');

        if ($userId === '' || $email === '' || $clientId === '' || $clientKind === '') {
            return null;
        }

        $active = $this->activeTokens()[$this->tokenIndex($userId, $clientId)] ?? null;
        if (!is_array($active)) {
            return null;
        }

        if (!hash_equals((string) ($active['access_token'] ?? ''), $rawToken)) {
            return null;
        }

        return [
            'user_id' => $userId,
            'email' => $email,
            'client_id' => $clientId,
            'client_kind' => $clientKind,
        ];
    }

    public function revoke(string $rawToken): bool
    {
        $token = $this->parseToken($rawToken);
        if (!$token instanceof UnencryptedToken) {
            return false;
        }

        $userId = (string) $token->claims()->get('sub', '');
        $clientId = (string) $token->claims()->get('client_id', '');
        if ($userId === '' || $clientId === '') {
            return false;
        }

        $index = $this->tokenIndex($userId, $clientId);
        $activeTokens = $this->activeTokens();
        $active = $activeTokens[$index] ?? null;
        if (!is_array($active)) {
            return false;
        }

        if (!hash_equals((string) ($active['access_token'] ?? ''), $rawToken)) {
            return false;
        }

        unset($activeTokens[$index]);
        $this->saveActiveTokens($activeTokens);

        return true;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function activeTokens(): array
    {
        $item = $this->cache->getItem(self::TOKEN_STORE_KEY);
        $value = $item->get();

        return is_array($value) ? $value : [];
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

    private function tokenIndex(string $userId, string $clientId): string
    {
        return $userId.'|'.$clientId;
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
