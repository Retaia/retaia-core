<?php

namespace App\Infrastructure\Derived;

use App\Application\Derived\Port\DerivedGateway as DerivedGatewayPort;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Derived\Service\DerivedUploadService;

final class DerivedGateway implements DerivedGatewayPort
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private DerivedUploadService $uploads,
    ) {
    }

    public function assetExists(string $assetUuid): bool
    {
        return $this->assets->findByUuid($assetUuid) !== null;
    }

    public function initUpload(string $assetUuid, string $kind, string $contentType, int $sizeBytes, ?string $sha256): array
    {
        return $this->uploads->init($assetUuid, $kind, $contentType, $sizeBytes, $sha256);
    }

    public function addUploadPart(string $uploadId, int $partNumber): bool
    {
        return $this->uploads->addPart($uploadId, $partNumber);
    }

    public function completeUpload(string $assetUuid, string $uploadId, int $totalParts): ?array
    {
        return $this->uploads->complete($assetUuid, $uploadId, $totalParts);
    }

    public function listDerivedForAsset(string $assetUuid): array
    {
        return $this->uploads->listForAsset($assetUuid);
    }

    public function findDerivedByAssetAndKind(string $assetUuid, string $kind): ?array
    {
        return $this->uploads->findByAssetAndKind($assetUuid, $kind);
    }
}
