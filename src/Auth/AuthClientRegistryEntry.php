<?php

namespace App\Auth;

final class AuthClientRegistryEntry
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientKind,
        public readonly ?string $secretKey,
        public readonly ?string $clientLabel,
        public readonly ?string $openPgpPublicKey,
        public readonly ?string $openPgpFingerprint,
        public readonly ?string $registeredAt,
        public readonly ?string $rotatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        $clientId = trim((string) ($row['client_id'] ?? ''));
        $clientKind = trim((string) ($row['client_kind'] ?? ''));
        if ($clientId === '' || $clientKind === '') {
            return null;
        }

        return new self(
            $clientId,
            $clientKind,
            self::nullableString($row['secret_key'] ?? null),
            self::nullableString($row['client_label'] ?? null),
            self::nullableString($row['openpgp_public_key'] ?? null),
            self::nullableString($row['openpgp_fingerprint'] ?? null),
            self::nullableString($row['registered_at'] ?? null),
            self::nullableString($row['rotated_at'] ?? null),
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toRow(): array
    {
        return [
            'client_id' => $this->clientId,
            'client_kind' => $this->clientKind,
            'secret_key' => $this->secretKey,
            'client_label' => $this->clientLabel,
            'openpgp_public_key' => $this->openPgpPublicKey,
            'openpgp_fingerprint' => $this->openPgpFingerprint,
            'registered_at' => $this->registeredAt,
            'rotated_at' => $this->rotatedAt,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
