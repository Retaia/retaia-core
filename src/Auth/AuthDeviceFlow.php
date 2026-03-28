<?php

namespace App\Auth;

final class AuthDeviceFlow
{
    public function __construct(
        public readonly string $deviceCode,
        public readonly string $userCode,
        public readonly string $clientKind,
        public readonly string $status,
        public readonly int $createdAt,
        public readonly int $expiresAt,
        public readonly int $intervalSeconds,
        public readonly int $lastPolledAt,
        public readonly ?string $approvedClientId,
        public readonly ?string $approvedSecretKey,
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

    /**
     * @return array<string, scalar|null>
     */
    public function toRow(): array
    {
        return [
            'device_code' => $this->deviceCode,
            'user_code' => $this->userCode,
            'client_kind' => $this->clientKind,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
            'interval_seconds' => $this->intervalSeconds,
            'last_polled_at' => $this->lastPolledAt,
            'approved_client_id' => $this->approvedClientId,
            'approved_secret_key' => $this->approvedSecretKey,
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
