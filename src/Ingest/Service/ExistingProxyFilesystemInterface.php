<?php

namespace App\Ingest\Service;

use App\Storage\BusinessStorageInterface;

interface ExistingProxyFilesystemInterface
{
    public function isFile(BusinessStorageInterface $storage, string $relativePath): bool;

    public function fileSize(BusinessStorageInterface $storage, string $relativePath): int;

    public function hashSha256(BusinessStorageInterface $storage, string $relativePath): ?string;

    public function materializeToDerived(BusinessStorageInterface $storage, string $assetUuid, string $kind, string $proxyPath): string;
}
