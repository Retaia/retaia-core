<?php

namespace App\Application\Derived\Port;

interface DerivedGateway
{
    public function assetExists(string $assetUuid): bool;

    /**
     * @return array<string, mixed>
     */
    public function initUpload(string $assetUuid, string $kind, string $contentType, int $sizeBytes, ?string $sha256): array;

    public function addUploadPart(string $uploadId, int $partNumber): bool;

    /**
     * @return array<string, mixed>|null
     */
    public function completeUpload(string $assetUuid, string $uploadId, int $totalParts): ?array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDerivedForAsset(string $assetUuid): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findDerivedByAssetAndKind(string $assetUuid, string $kind): ?array;
}
