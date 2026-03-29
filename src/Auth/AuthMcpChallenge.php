<?php

namespace App\Auth;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'auth_mcp_challenge')]
#[ORM\Index(name: 'idx_auth_mcp_challenge_expires_at', columns: ['expires_at'])]
final class AuthMcpChallenge
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'challenge_id', type: 'string', length: 32)]
        public string $challengeId,
        #[ORM\Column(name: 'client_id', type: 'string', length: 64)]
        public string $clientId,
        #[ORM\Column(name: 'openpgp_fingerprint', type: 'string', length: 40)]
        public string $openPgpFingerprint,
        #[ORM\Column(name: 'challenge', type: 'string', length: 128)]
        public string $challenge,
        #[ORM\Column(name: 'expires_at', type: 'bigint')]
        public int $expiresAt,
        #[ORM\Column(name: 'used', type: 'boolean')]
        public bool $used,
        #[ORM\Column(name: 'used_at', type: 'bigint', nullable: true)]
        public ?int $usedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        $challengeId = trim((string) ($row['challenge_id'] ?? ''));
        $clientId = trim((string) ($row['client_id'] ?? ''));
        $fingerprint = trim((string) ($row['openpgp_fingerprint'] ?? ''));
        $challenge = trim((string) ($row['challenge'] ?? ''));
        if ($challengeId === '' || $clientId === '' || $fingerprint === '' || $challenge === '') {
            return null;
        }

        $usedAt = $row['used_at'] ?? null;

        return new self(
            $challengeId,
            $clientId,
            $fingerprint,
            $challenge,
            (int) ($row['expires_at'] ?? 0),
            (bool) ($row['used'] ?? false),
            is_numeric($usedAt) ? (int) $usedAt : null,
        );
    }

    public function syncFrom(self $challenge): void
    {
        $this->clientId = $challenge->clientId;
        $this->openPgpFingerprint = $challenge->openPgpFingerprint;
        $this->challenge = $challenge->challenge;
        $this->expiresAt = $challenge->expiresAt;
        $this->used = $challenge->used;
        $this->usedAt = $challenge->usedAt;
    }
}
