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
    ) {
        $keyMaterial = hash('sha256', $secret);
        $this->jwt = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($keyMaterial)
        );
    }

    /**
     * @return array{access_token: string, token_type: string, client_id: string, client_kind: string}
     */
    public function issue(User $user, string $clientId, string $clientKind): array
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimestamp(time());
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->ttlSeconds));

        $token = $this->jwt->builder()
            ->issuedBy('retaia-core')
            ->issuedAt($now)
            ->expiresAt($expiresAt)
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->relatedTo($user->getId())
            ->withClaim('email', $user->getEmail())
            ->withClaim('client_id', $clientId)
            ->withClaim('client_kind', $clientKind)
            ->withClaim('actor_kind', 'USER_INTERACTIVE')
            ->getToken($this->jwt->signer(), $this->jwt->signingKey())
            ->toString();

        $activeTokens = $this->activeTokens();
        $activeTokens[$this->tokenIndex($user->getId(), $clientId)] = [
            'access_token' => $token,
            'user_id' => $user->getId(),
            'email' => $user->getEmail(),
            'client_id' => $clientId,
            'client_kind' => $clientKind,
            'issued_at' => time(),
        ];
        $this->saveActiveTokens($activeTokens);

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
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
