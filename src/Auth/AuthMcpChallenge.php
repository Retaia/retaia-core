<?php

namespace App\Auth;

final class AuthMcpChallenge
{
    public function __construct(
        public readonly string $challengeId,
        public readonly string $clientId,
        public readonly string $openPgpFingerprint,
        public readonly string $challenge,
        public readonly int $expiresAt,
        public readonly bool $used,
        public readonly ?int $usedAt,
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

    /**
     * @return array<string, scalar|null>
     */
    public function toRow(): array
    {
        return [
            'challenge_id' => $this->challengeId,
            'client_id' => $this->clientId,
            'openpgp_fingerprint' => $this->openPgpFingerprint,
            'challenge' => $this->challenge,
            'expires_at' => $this->expiresAt,
            'used' => $this->used ? 1 : 0,
            'used_at' => $this->usedAt,
        ];
    }
}
