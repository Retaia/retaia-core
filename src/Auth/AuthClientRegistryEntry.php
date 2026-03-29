<?php

namespace App\Auth;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'auth_client_registry')]
final class AuthClientRegistryEntry
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'client_id', type: 'string', length: 64)]
        public string $clientId,
        #[ORM\Column(name: 'client_kind', type: 'string', length: 32)]
        public string $clientKind,
        #[ORM\Column(name: 'secret_key', type: 'string', length: 128, nullable: true)]
        public ?string $secretKey,
        #[ORM\Column(name: 'client_label', type: 'string', length: 255, nullable: true)]
        public ?string $clientLabel,
        #[ORM\Column(name: 'openpgp_public_key', type: 'text', nullable: true)]
        public ?string $openPgpPublicKey,
        #[ORM\Column(name: 'openpgp_fingerprint', type: 'string', length: 40, nullable: true)]
        public ?string $openPgpFingerprint,
        #[ORM\Column(name: 'registered_at', type: 'string', length: 32, nullable: true)]
        public ?string $registeredAt,
        #[ORM\Column(name: 'rotated_at', type: 'string', length: 32, nullable: true)]
        public ?string $rotatedAt,
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

    public function syncFrom(self $entry): void
    {
        $this->clientKind = $entry->clientKind;
        $this->secretKey = $entry->secretKey;
        $this->clientLabel = $entry->clientLabel;
        $this->openPgpPublicKey = $entry->openPgpPublicKey;
        $this->openPgpFingerprint = $entry->openPgpFingerprint;
        $this->registeredAt = $entry->registeredAt;
        $this->rotatedAt = $entry->rotatedAt;
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
