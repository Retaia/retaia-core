<?php

namespace App\Ingest\Service;

use App\Storage\BusinessStorageInterface;
use Doctrine\DBAL\Connection;

final class IngestDerivedOutboxMover
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<string, string> oldPath => newPath
     */
    public function moveForAsset(BusinessStorageInterface $storage, string $assetUuid, string $targetFolder): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, storage_path FROM asset_derived_file WHERE asset_uuid = :assetUuid',
            ['assetUuid' => $assetUuid]
        );

        $remap = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            $storagePathRaw = (string) ($row['storage_path'] ?? '');
            $storagePath = $this->normalizeRelativePath($storagePathRaw);
            if ($id === '' || $storagePath === null) {
                continue;
            }

            $targetStoragePath = $targetFolder.'/'.$storagePath;
            if ($storage->fileExists($storagePath)) {
                $storage->move($storagePath, $targetStoragePath);
            } elseif (!$storage->fileExists($targetStoragePath)) {
                continue;
            }

            $this->connection->update('asset_derived_file', ['storage_path' => $targetStoragePath], ['id' => $id]);
            $remap[$storagePath] = $targetStoragePath;
        }

        return $remap;
    }

    private function normalizeRelativePath(string $path): ?string
    {
        $normalized = ltrim(trim($path), '/');
        if ($normalized === '' || str_contains($normalized, "\0") || str_contains($normalized, '..\\') || str_contains($normalized, '../')) {
            return null;
        }

        return $normalized;
    }
}
