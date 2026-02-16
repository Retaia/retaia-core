<?php

namespace App\Domain\AuthClient;

final class DeviceFlowStatus
{
    public const PENDING = 'PENDING';
    public const APPROVED = 'APPROVED';
    public const DENIED = 'DENIED';
    public const EXPIRED = 'EXPIRED';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::PENDING, self::APPROVED, self::DENIED, self::EXPIRED];
    }

    public static function isKnown(string $status): bool
    {
        return in_array($status, self::all(), true);
    }
}
