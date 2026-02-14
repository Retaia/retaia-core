<?php

namespace App\Auth;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ClientAccessTokenFactory
{
    private Configuration $jwt;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        string $secret,
        #[Autowire('%app.client_token_ttl_seconds%')]
        private int $ttlSeconds,
    ) {
        $keyMaterial = hash('sha256', $secret);
        $this->jwt = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($keyMaterial)
        );
    }

    public function issue(string $clientId, string $clientKind): string
    {
        $now = new \DateTimeImmutable();
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->ttlSeconds));

        return $this->jwt->builder()
            ->issuedBy('retaia-core')
            ->issuedAt($now)
            ->expiresAt($expiresAt)
            ->relatedTo($clientId)
            ->withClaim('client_id', $clientId)
            ->withClaim('client_kind', $clientKind)
            ->getToken($this->jwt->signer(), $this->jwt->signingKey())
            ->toString();
    }
}
