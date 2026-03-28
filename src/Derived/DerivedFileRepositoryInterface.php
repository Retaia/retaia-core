<?php

namespace App\Derived;

interface DerivedFileRepositoryInterface
{
    public function create(string $assetUuid, string $kind, string $contentType, int $sizeBytes, ?string $sha256): DerivedFile;

    /** @return list<DerivedFile> */
    public function listByAsset(string $assetUuid): array;

    public function findLatestByAssetAndKind(string $assetUuid, string $kind): ?DerivedFile;

    /** @return list<string> */
    public function listStoragePathsByAsset(string $assetUuid): array;

    public function deleteByAsset(string $assetUuid): void;
}
