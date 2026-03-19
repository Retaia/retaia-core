<?php

namespace App\Asset;

use App\Entity\Asset;

final class AssetRevisionTag
{
    public static function fromAsset(Asset $asset): string
    {
        return self::fromParts($asset->getUuid(), $asset->getUpdatedAt()->format(DATE_ATOM));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): ?string
    {
        $uuid = trim((string) ($payload['uuid'] ?? ''));
        $updatedAt = trim((string) ($payload['updated_at'] ?? ''));
        if ($uuid === '' || $updatedAt === '') {
            return null;
        }

        return self::fromParts($uuid, $updatedAt);
    }

    private static function fromParts(string $uuid, string $updatedAt): string
    {
        return '"'.hash('sha256', $uuid.'|'.$updatedAt).'"';
    }
}
