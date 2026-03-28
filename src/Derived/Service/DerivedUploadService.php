<?php

namespace App\Derived\Service;

use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Derived\DerivedFile;
use App\Derived\DerivedFileRepositoryInterface;
use App\Derived\DerivedUploadSession;
use App\Derived\DerivedUploadSessionRepositoryInterface;

final class DerivedUploadService
{
    public function __construct(
        private DerivedUploadSessionRepositoryInterface $sessions,
        private DerivedFileRepositoryInterface $files,
        private AssetRepositoryInterface $assets,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function init(string $assetUuid, string $kind, string $contentType, int $sizeBytes, ?string $sha256): array
    {
        $this->storageIdForAssetUuid($assetUuid);
        $session = $this->sessions->create($assetUuid, $kind, $contentType, $sizeBytes, $sha256);

        return [
            'upload_id' => $session->uploadId,
            'part_size_bytes' => 5 * 1024 * 1024,
            'status' => $session->status,
        ];
    }

    public function addPart(string $uploadId, int $partNumber): bool
    {
        $session = $this->sessions->find($uploadId);
        if (!$session instanceof DerivedUploadSession || !$session->isOpen()) {
            return false;
        }

        $this->sessions->updateHighestPartCount($uploadId, $partNumber);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function complete(string $assetUuid, string $uploadId, int $totalParts): ?array
    {
        $session = $this->sessions->find($uploadId);
        if (!$session instanceof DerivedUploadSession || !$session->isOpen()) {
            return null;
        }

        if ($session->assetUuid !== $assetUuid) {
            return null;
        }

        if ($session->partsCount < $totalParts) {
            return null;
        }

        $file = $this->files->create(
            $assetUuid,
            $session->kind,
            $session->contentType,
            $session->sizeBytes,
            $session->sha256,
        );
        $this->sessions->markCompleted($uploadId);

        return $this->serializeFile($file);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForAsset(string $assetUuid): array
    {
        return array_map(fn (DerivedFile $file): array => $this->serializeFile($file), $this->files->listByAsset($assetUuid));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByAssetAndKind(string $assetUuid, string $kind): ?array
    {
        $file = $this->files->findLatestByAssetAndKind($assetUuid, $kind);

        return $file instanceof DerivedFile ? $this->serializeFile($file) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFile(DerivedFile $file): array
    {
        return [
            'id' => $file->id,
            'asset_uuid' => $file->assetUuid,
            'kind' => $file->kind,
            'content_type' => $file->contentType,
            'size_bytes' => $file->sizeBytes,
            'sha256' => $file->sha256,
            'url' => sprintf('/api/v1/assets/%s/derived/%s', $file->assetUuid, $file->kind),
            'created_at' => $file->createdAt->format(DATE_ATOM),
        ];
    }

    private function storageIdForAssetUuid(string $assetUuid): string
    {
        $asset = $this->assets->findByUuid($assetUuid);
        if (!$asset instanceof Asset) {
            throw new \RuntimeException(sprintf('Asset %s not found while resolving derived storage.', $assetUuid));
        }

        $fields = $asset->getFields();
        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $storageId = trim((string) ($paths['storage_id'] ?? $fields['storage_id'] ?? ''));
        if ($storageId === '') {
            throw new \RuntimeException(sprintf('Asset %s is missing storage_id for derived persistence.', $assetUuid));
        }

        return $storageId;
    }
}
