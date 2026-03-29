<?php

namespace App\Auth;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'auth_device_flow')]
#[ORM\UniqueConstraint(name: 'uniq_auth_device_flow_user_code', columns: ['user_code'])]
final class AuthDeviceFlow
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'device_code', type: 'string', length: 32)]
        public string $deviceCode,
        #[ORM\Column(name: 'user_code', type: 'string', length: 16)]
        public string $userCode,
        #[ORM\Column(name: 'client_kind', type: 'string', length: 32)]
        public string $clientKind,
        #[ORM\Column(name: 'status', type: 'string', length: 16)]
        public string $status,
        #[ORM\Column(name: 'created_at', type: 'bigint')]
        public int $createdAt,
        #[ORM\Column(name: 'expires_at', type: 'bigint')]
        public int $expiresAt,
        #[ORM\Column(name: 'interval_seconds', type: 'integer')]
        public int $intervalSeconds,
        #[ORM\Column(name: 'last_polled_at', type: 'bigint')]
        public int $lastPolledAt,
        #[ORM\Column(name: 'approved_client_id', type: 'string', length: 64, nullable: true)]
        public ?string $approvedClientId,
        #[ORM\Column(name: 'approved_secret_key', type: 'string', length: 128, nullable: true)]
        public ?string $approvedSecretKey,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        $deviceCode = trim((string) ($row['device_code'] ?? ''));
        $userCode = trim((string) ($row['user_code'] ?? ''));
        $clientKind = trim((string) ($row['client_kind'] ?? ''));
        $status = trim((string) ($row['status'] ?? ''));
        if ($deviceCode === '' || $userCode === '' || $clientKind === '' || $status === '') {
            return null;
        }

        return new self(
            $deviceCode,
            $userCode,
            $clientKind,
            $status,
            (int) ($row['created_at'] ?? time()),
            (int) ($row['expires_at'] ?? 0),
            (int) ($row['interval_seconds'] ?? 5),
            (int) ($row['last_polled_at'] ?? 0),
            self::nullableString($row['approved_client_id'] ?? null),
            self::nullableString($row['approved_secret_key'] ?? null),
        );
    }

    public function syncFrom(self $flow): void
    {
        $this->userCode = $flow->userCode;
        $this->clientKind = $flow->clientKind;
        $this->status = $flow->status;
        $this->createdAt = $flow->createdAt;
        $this->expiresAt = $flow->expiresAt;
        $this->intervalSeconds = $flow->intervalSeconds;
        $this->lastPolledAt = $flow->lastPolledAt;
        $this->approvedClientId = $flow->approvedClientId;
        $this->approvedSecretKey = $flow->approvedSecretKey;
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
