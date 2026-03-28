<?php

namespace App\Ingest\Service;

interface ExistingProxyFilesystemInterface
{
    public function isFile(string $root, string $relativePath): bool;

    public function fileSize(string $root, string $relativePath): int;

    public function hashSha256(string $root, string $relativePath): ?string;

    public function materializeToDerived(string $root, string $assetUuid, string $kind, string $proxyPath): string;
}
