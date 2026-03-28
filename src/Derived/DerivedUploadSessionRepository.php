<?php

namespace App\Derived;

use Doctrine\DBAL\Connection;

final class DerivedUploadSessionRepository implements DerivedUploadSessionRepositoryInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function create(string $assetUuid, string $kind, string $contentType, int $sizeBytes, ?string $sha256): DerivedUploadSession
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
            'status' => 'open',
            'parts_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new DerivedUploadSession($uploadId, $assetUuid, $kind, $contentType, $sizeBytes, $sha256, 'open', 0);
    }

    public function find(string $uploadId): ?DerivedUploadSession
    {
        $row = $this->connection->fetchAssociative(
            'SELECT upload_id, asset_uuid, kind, content_type, size_bytes, sha256, status, parts_count
             FROM derived_upload_session
             WHERE upload_id = :uploadId',
            ['uploadId' => $uploadId]
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function updateHighestPartCount(string $uploadId, int $partNumber): void
    {
        $this->connection->executeStatement(
            'UPDATE derived_upload_session SET parts_count = CASE WHEN parts_count > :partNumber THEN parts_count ELSE :partNumber END, updated_at = :updatedAt WHERE upload_id = :uploadId',
            [
                'partNumber' => $partNumber,
                'updatedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'uploadId' => $uploadId,
            ]
        );
    }

    public function markCompleted(string $uploadId): void
    {
        $this->connection->update('derived_upload_session', [
            'status' => 'completed',
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], [
            'upload_id' => $uploadId,
        ]);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): DerivedUploadSession
    {
        return new DerivedUploadSession(
            (string) $row['upload_id'],
            (string) $row['asset_uuid'],
            (string) $row['kind'],
            (string) $row['content_type'],
            (int) $row['size_bytes'],
            is_string($row['sha256'] ?? null) ? $row['sha256'] : null,
            (string) $row['status'],
            (int) $row['parts_count'],
        );
    }
}
