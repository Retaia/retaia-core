<?php

namespace App\Api\Service\AgentSignature;

final class AgentSignatureNonceRecord
{
    public function __construct(
        public readonly string $nonceKey,
        public readonly string $agentId,
        public readonly int $expiresAt,
        public readonly int $consumedAt,
    ) {
    }

    public static function create(string $agentId, string $nonce, int $ttlSeconds, int $now): ?self
    {
        $agentId = trim($agentId);
        $nonce = trim($nonce);
        if ($agentId === '' || $nonce === '' || $ttlSeconds < 1 || $now < 1) {
            return null;
        }

        return new self(
            hash('sha256', $agentId.'|'.$nonce),
            $agentId,
            $now + $ttlSeconds,
            $now
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        $nonceKey = trim((string) ($row['nonce_key'] ?? ''));
        $agentId = trim((string) ($row['agent_id'] ?? ''));
        $expiresAt = is_numeric($row['expires_at'] ?? null) ? (int) $row['expires_at'] : 0;
        $consumedAt = is_numeric($row['consumed_at'] ?? null) ? (int) $row['consumed_at'] : 0;

        if ($nonceKey === '' || $agentId === '' || $expiresAt < 1 || $consumedAt < 1) {
            return null;
        }

        return new self($nonceKey, $agentId, $expiresAt, $consumedAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'nonce_key' => $this->nonceKey,
            'agent_id' => $this->agentId,
            'expires_at' => $this->expiresAt,
            'consumed_at' => $this->consumedAt,
        ];
    }
}
