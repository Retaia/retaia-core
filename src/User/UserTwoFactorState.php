<?php

namespace App\User;

final class UserTwoFactorState
{
    /**
     * @param list<string> $recoveryCodeHashes
     * @param list<string> $legacyRecoveryCodeSha256
     */
    public function __construct(
        public readonly string $userId,
        public readonly bool $enabled,
        public readonly ?string $pendingSecretEncrypted,
        public readonly ?string $secretEncrypted,
        public readonly array $recoveryCodeHashes,
        public readonly array $legacyRecoveryCodeSha256,
        public readonly int $createdAt,
        public readonly int $updatedAt,
    ) {
    }

    public static function empty(string $userId, ?int $timestamp = null): self
    {
        $now = $timestamp ?? time();

        return new self($userId, false, null, null, [], [], $now, $now);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        $userId = trim((string) ($row['user_id'] ?? ''));
        if ($userId === '') {
            return null;
        }

        return new self(
            $userId,
            (bool) ($row['enabled'] ?? false),
            self::normalizeNullableString($row['pending_secret_encrypted'] ?? null),
            self::normalizeNullableString($row['secret_encrypted'] ?? null),
            self::decodeStringList($row['recovery_code_hashes'] ?? '[]'),
            self::decodeStringList($row['legacy_recovery_code_sha256'] ?? '[]'),
            max(0, (int) ($row['created_at'] ?? time())),
            max(0, (int) ($row['updated_at'] ?? ($row['created_at'] ?? time()))),
        );
    }

    /**
     * @return array<string, scalar>
     */
    public function toRow(): array
    {
        return [
            'user_id' => $this->userId,
            'enabled' => $this->enabled ? 1 : 0,
            'pending_secret_encrypted' => $this->pendingSecretEncrypted,
            'secret_encrypted' => $this->secretEncrypted,
            'recovery_code_hashes' => json_encode(array_values($this->recoveryCodeHashes), JSON_THROW_ON_ERROR),
            'legacy_recovery_code_sha256' => json_encode(array_values($this->legacyRecoveryCodeSha256), JSON_THROW_ON_ERROR),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toStateArray(): array
    {
        $state = [
            'enabled' => $this->enabled,
            'recovery_code_hashes' => array_values($this->recoveryCodeHashes),
        ];

        if ($this->pendingSecretEncrypted !== null && $this->pendingSecretEncrypted !== '') {
            $state['pending_secret_encrypted'] = $this->pendingSecretEncrypted;
        }
        if ($this->secretEncrypted !== null && $this->secretEncrypted !== '') {
            $state['secret_encrypted'] = $this->secretEncrypted;
        }
        if ($this->legacyRecoveryCodeSha256 !== []) {
            $state['recovery_code_sha256'] = array_values($this->legacyRecoveryCodeSha256);
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function fromStateArray(string $userId, array $state, ?self $existing = null, ?int $timestamp = null): self
    {
        $now = $timestamp ?? time();

        return new self(
            $userId,
            (bool) ($state['enabled'] ?? false),
            self::normalizeNullableString($state['pending_secret_encrypted'] ?? null),
            self::normalizeNullableString($state['secret_encrypted'] ?? null),
            self::filterStringList((array) ($state['recovery_code_hashes'] ?? [])),
            self::filterStringList((array) ($state['recovery_code_sha256'] ?? [])),
            $existing?->createdAt ?? $now,
            $now,
        );
    }

    private static function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function decodeStringList(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }

            return self::filterStringList(is_array($decoded) ? $decoded : []);
        }

        return self::filterStringList(is_array($value) ? $value : []);
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private static function filterStringList(array $values): array
    {
        return array_values(array_filter(
            $values,
            static fn (mixed $value): bool => is_string($value) && trim($value) !== ''
        ));
    }
}
