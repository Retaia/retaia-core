<?php

namespace App\Derived;

use Doctrine\DBAL\Connection;

final class DerivedFileRepository implements DerivedFileRepositoryInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function create(string $assetUuid, string $kind, string $contentType, int $sizeBytes, ?string $sha256): DerivedFile
    {
        $id = bin2hex(random_bytes(8));
        $createdAt = new \DateTimeImmutable();
        $storagePath = sprintf('/derived/%s/%s', $assetUuid, $id);

        $this->connection->insert('asset_derived_file', [
            'id' => $id,
            'asset_uuid' => $assetUuid,
            'kind' => $kind,
            'content_type' => $contentType,
            'size_bytes' => $sizeBytes,
            'sha256' => $sha256,
            'storage_path' => $storagePath,
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
        ]);

        return new DerivedFile($id, $assetUuid, $kind, $contentType, $sizeBytes, $sha256, $storagePath, $createdAt);
    }

    public function listByAsset(string $assetUuid): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, asset_uuid, kind, content_type, size_bytes, sha256, storage_path, created_at
             FROM asset_derived_file
             WHERE asset_uuid = :assetUuid
             ORDER BY created_at DESC',
            ['assetUuid' => $assetUuid]
        );

        return array_map(fn (array $row): DerivedFile => $this->hydrate($row), $rows);
    }

    public function findLatestByAssetAndKind(string $assetUuid, string $kind): ?DerivedFile
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, asset_uuid, kind, content_type, size_bytes, sha256, storage_path, created_at
             FROM asset_derived_file
             WHERE asset_uuid = :assetUuid AND kind = :kind
             ORDER BY created_at DESC
             LIMIT 1',
            ['assetUuid' => $assetUuid, 'kind' => $kind]
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function listStoragePathsByAsset(string $assetUuid): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT storage_path FROM asset_derived_file WHERE asset_uuid = :assetUuid',
            ['assetUuid' => $assetUuid]
        );

        return array_values(array_filter(array_map(static fn (array $row): ?string => is_string($row['storage_path'] ?? null) ? $row['storage_path'] : null, $rows)));
    }

    public function deleteByAsset(string $assetUuid): void
    {
        $this->connection->executeStatement('DELETE FROM asset_derived_file WHERE asset_uuid = :assetUuid', ['assetUuid' => $assetUuid]);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): DerivedFile
    {
        return new DerivedFile(
            (string) $row['id'],
            (string) $row['asset_uuid'],
            (string) $row['kind'],
            (string) $row['content_type'],
            (int) $row['size_bytes'],
            is_string($row['sha256'] ?? null) ? $row['sha256'] : null,
            (string) $row['storage_path'],
            is_string($row['created_at'] ?? null) ? new \DateTimeImmutable((string) $row['created_at']) : new \DateTimeImmutable(),
        );
    }
}
