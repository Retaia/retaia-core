<?php

namespace App\Auth;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class UserAccessJwtService
{
    private Configuration $jwt;

    public function __construct(
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
     * @return array{token: string, expires_at: int}
     */
    public function issue(
        string $userId,
        string $email,
        string $sessionId,
        string $clientId,
        string $clientKind,
        int $issuedAt,
    ): array {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimestamp($issuedAt);
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->ttlSeconds));

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

        return [
            'token' => $token,
            'expires_at' => $expiresAt->getTimestamp(),
        ];
    }

    /**
     * @return array{user_id: string, email: string, client_id: string, client_kind: string, session_id: string}|null
     */
    public function validate(string $rawToken): ?array
    {
        $token = $this->parse($rawToken);
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

        return [
            'user_id' => $userId,
            'email' => $email,
            'client_id' => $clientId,
            'client_kind' => $clientKind,
            'session_id' => $sessionId,
        ];
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    private function parse(string $rawToken): ?UnencryptedToken
    {
        try {
            $token = $this->jwt->parser()->parse($rawToken);
        } catch (\Throwable) {
            return null;
        }

        return $token instanceof UnencryptedToken ? $token : null;
    }
}
