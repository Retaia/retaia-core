<?php

namespace App\Ingest\Repository;

use Doctrine\DBAL\Connection;

final class PathAuditRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function record(string $assetUuid, string $fromPath, string $toPath, string $reason, \DateTimeImmutable $at): void
    {
        $this->connection->insert('ingest_path_audit', [
            'id' => bin2hex(random_bytes(16)),
            'asset_uuid' => $assetUuid,
            'from_path' => $fromPath,
            'to_path' => $toPath,
            'reason' => $reason,
            'created_at' => $at->format('Y-m-d H:i:s'),
        ]);
    }
}

