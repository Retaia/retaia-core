<?php

namespace App\Derived\Service;

use Doctrine\DBAL\Connection;

final class DerivedUploadService
{
    private const STATUS_OPEN = 'open';
    private const STATUS_COMPLETED = 'completed';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function init(string $assetUuid, string $kind, string $contentType, int $sizeBytes, ?string $sha256): array
    {
        $uploadId = bin2hex(random_bytes(12));
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->insert('derived_upload_session', [
            'upload_id' => $uploadId,
            'asset_uuid' => $assetUuid,
            'kind' => $kind,
            'content_type' => $contentType,
            'size_bytes' => $sizeBytes,
            'sha256' => $sha256,
            'status' => self::STATUS_OPEN,
            'parts_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'upload_id' => $uploadId,
            'part_size_bytes' => 5 * 1024 * 1024,
            'status' => self::STATUS_OPEN,
        ];
    }

    public function addPart(string $uploadId, int $partNumber): bool
    {
        $session = $this->connection->fetchAssociative(
            'SELECT upload_id, status, parts_count FROM derived_upload_session WHERE upload_id = :uploadId',
            ['uploadId' => $uploadId]
        );

        if (!is_array($session) || ($session['status'] ?? null) !== self::STATUS_OPEN) {
            return false;
        }

        $current = (int) ($session['parts_count'] ?? 0);
        $newCount = max($current, $partNumber);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->update('derived_upload_session', [
            'parts_count' => $newCount,
            'updated_at' => $now,
        ], [
            'upload_id' => $uploadId,
        ]);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function complete(string $assetUuid, string $uploadId, int $totalParts): ?array
    {
        $session = $this->connection->fetchAssociative(
            'SELECT upload_id, asset_uuid, kind, content_type, size_bytes, sha256, status, parts_count
             FROM derived_upload_session
             WHERE upload_id = :uploadId',
            ['uploadId' => $uploadId]
        );

        if (!is_array($session) || ($session['status'] ?? null) !== self::STATUS_OPEN) {
            return null;
        }

        if (($session['asset_uuid'] ?? null) !== $assetUuid) {
            return null;
        }

        if ((int) ($session['parts_count'] ?? 0) < $totalParts) {
            return null;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $id = bin2hex(random_bytes(8));

        $this->connection->insert('asset_derived_file', [
            'id' => $id,
            'asset_uuid' => $assetUuid,
            'kind' => (string) $session['kind'],
            'content_type' => (string) $session['content_type'],
            'size_bytes' => (int) $session['size_bytes'],
            'sha256' => is_string($session['sha256'] ?? null) ? $session['sha256'] : null,
            'storage_path' => sprintf('/derived/%s/%s', $assetUuid, $id),
            'created_at' => $now,
        ]);

        $this->connection->update('derived_upload_session', [
            'status' => self::STATUS_COMPLETED,
            'updated_at' => $now,
        ], [
            'upload_id' => $uploadId,
        ]);

        return [
            'id' => $id,
            'asset_uuid' => $assetUuid,
            'kind' => (string) $session['kind'],
            'content_type' => (string) $session['content_type'],
            'size_bytes' => (int) $session['size_bytes'],
            'sha256' => is_string($session['sha256'] ?? null) ? $session['sha256'] : null,
            'url' => sprintf('/api/v1/assets/%s/derived/%s', $assetUuid, (string) $session['kind']),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForAsset(string $assetUuid): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, asset_uuid, kind, content_type, size_bytes, sha256, storage_path, created_at
             FROM asset_derived_file
             WHERE asset_uuid = :assetUuid
             ORDER BY created_at DESC',
            ['assetUuid' => $assetUuid]
        );

        return array_map(fn (array $row): array => $this->normalizeRow($row), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByAssetAndKind(string $assetUuid, string $kind): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, asset_uuid, kind, content_type, size_bytes, sha256, storage_path, created_at
             FROM asset_derived_file
             WHERE asset_uuid = :assetUuid AND kind = :kind
             ORDER BY created_at DESC
             LIMIT 1',
            [
                'assetUuid' => $assetUuid,
                'kind' => $kind,
            ]
        );

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'asset_uuid' => (string) $row['asset_uuid'],
            'kind' => (string) $row['kind'],
            'content_type' => (string) $row['content_type'],
            'size_bytes' => (int) $row['size_bytes'],
            'sha256' => isset($row['sha256']) ? (string) $row['sha256'] : null,
            'url' => sprintf('/api/v1/assets/%s/derived/%s', (string) $row['asset_uuid'], (string) $row['kind']),
            'created_at' => is_string($row['created_at'] ?? null) ? (new \DateTimeImmutable((string) $row['created_at']))->format(DATE_ATOM) : null,
        ];
    }
}
