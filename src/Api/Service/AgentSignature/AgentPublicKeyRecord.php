<?php

namespace App\Api\Service\AgentSignature;

final class AgentPublicKeyRecord
{
    public function __construct(
        public readonly string $agentId,
        public readonly string $fingerprint,
        public readonly string $publicKey,
        public readonly int $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        $agentId = trim((string) ($row['agent_id'] ?? ''));
        $fingerprint = strtoupper(preg_replace('/\s+/', '', trim((string) ($row['openpgp_fingerprint'] ?? ''))) ?? '');
        $publicKey = trim((string) ($row['openpgp_public_key'] ?? ''));
        $updatedAt = is_numeric($row['updated_at'] ?? null) ? (int) $row['updated_at'] : 0;

        if ($agentId === '' || preg_match('/^[A-F0-9]{40}$/', $fingerprint) !== 1 || $publicKey === '' || $updatedAt < 1) {
            return null;
        }

        return new self($agentId, $fingerprint, $publicKey, $updatedAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'agent_id' => $this->agentId,
            'openpgp_fingerprint' => $this->fingerprint,
            'openpgp_public_key' => $this->publicKey,
            'updated_at' => $this->updatedAt,
        ];
    }
}
