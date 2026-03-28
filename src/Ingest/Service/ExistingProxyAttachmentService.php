<?php

namespace App\Ingest\Service;

use App\Asset\Repository\AssetRepositoryInterface;
use App\Derived\DerivedFileRepositoryInterface;
use App\Entity\Asset;
use App\Ingest\Repository\IngestDiagnosticsRepository;
use App\Storage\BusinessStorageRegistryInterface;

final class ExistingProxyAttachmentService
{
    public function __construct(
        private BusinessStorageRegistryInterface $storageRegistry,
        private ExistingProxyFilesystemInterface $filesystem,
        private IngestDiagnosticsRepository $ingestDiagnostics,
        private AssetRepositoryInterface $assets,
        private DerivedFileRepositoryInterface $derivedFiles,
    ) {
    }

    /**
     * @param array{path:string,type:string,kind:string,original:string} $proxy
     */
    public function canUse(string $storageId, array $proxy, string $assetUuid): bool
    {
        $kind = (string) ($proxy['kind'] ?? '');
        $path = (string) ($proxy['path'] ?? '');
        if ($kind === '' || $path === '') {
            return false;
        }

        $storage = $this->storageRegistry->get($storageId)->storage;

        if ($this->filesystem->isFile($storage, $path)) {
            return $this->filesystem->fileSize($storage, $path) > 0;
        }

        $existing = $this->derivedFiles->findLatestByAssetAndKind($assetUuid, $kind);
        if ($existing === null) {
            return false;
        }

        if (!$this->filesystem->isFile($storage, $existing->storagePath)) {
            return false;
        }

        return $this->filesystem->fileSize($storage, $existing->storagePath) > 0;
    }

    /**
     * @param array{path:string,type:string,kind:string,original:string} $proxy
     */
    public function attachToAsset(Asset $asset, string $storageId, string $originalPath, array $proxy): void
    {
        $fields = $asset->getFields();

        $proxyPath = (string) ($proxy['path'] ?? '');
        $proxyKind = (string) ($proxy['kind'] ?? '');
        if ($proxyPath === '' || $proxyKind === '') {
            return;
        }
        $this->ingestDiagnostics->clearUnmatchedSidecar($proxyPath);

        $materializedStoragePath = $this->persistDerivedFile($storageId, $asset->getUuid(), $proxyKind, $proxyPath);

        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $sidecars = is_array($paths['sidecars_relative'] ?? null) ? $paths['sidecars_relative'] : [];
        $sidecars = array_values(array_filter(array_map('strval', $sidecars), static fn (string $item): bool => $item !== $proxyPath && $item !== ''));
        if (!in_array($materializedStoragePath, $sidecars, true)) {
            $sidecars[] = $materializedStoragePath;
        }
        $paths['storage_id'] = (string) ($paths['storage_id'] ?? $storageId);
        $paths['original_relative'] = (string) ($paths['original_relative'] ?? $originalPath);
        $paths['sidecars_relative'] = array_values(array_unique(array_map('strval', $sidecars)));

        $derived = is_array($fields['derived'] ?? null) ? $fields['derived'] : [];
        $manifest = is_array($derived['derived_manifest'] ?? null) ? $derived['derived_manifest'] : [];

        $alreadyInManifest = false;
        foreach ($manifest as $item) {
            if (is_array($item)
                && (string) ($item['kind'] ?? '') === $proxyKind
                && (string) ($item['ref'] ?? '') === $materializedStoragePath
            ) {
                $alreadyInManifest = true;
                break;
            }
        }
        if (!$alreadyInManifest) {
            $manifest[] = [
                'kind' => $proxyKind,
                'ref' => $materializedStoragePath,
            ];
        }

        $derived['derived_manifest'] = $manifest;
        $derived[sprintf('%s_url', $proxyKind)] = sprintf('/api/v1/assets/%s/derived/%s', $asset->getUuid(), $proxyKind);
        $fields['paths'] = $paths;
        $fields['derived'] = $derived;
        $fields['proxy_done'] = true;
        $asset->setFields($fields);
        $this->assets->save($asset);
    }

    private function persistDerivedFile(string $storageId, string $assetUuid, string $kind, string $proxyPath): string
    {
        $storage = $this->storageRegistry->get($storageId)->storage;
        $storagePath = $this->filesystem->materializeToDerived($storage, $assetUuid, $kind, $proxyPath);
        $size = $this->filesystem->isFile($storage, $storagePath) ? $this->filesystem->fileSize($storage, $storagePath) : 0;
        $sha256 = $this->filesystem->isFile($storage, $storagePath) ? $this->filesystem->hashSha256($storage, $storagePath) : null;

        $this->derivedFiles->upsertMaterialized(
            $assetUuid,
            $kind,
            $this->contentTypeForDerivedKind($kind, $storagePath),
            is_int($size) ? $size : 0,
            is_string($sha256) ? $sha256 : null,
            $storagePath,
        );

        return $storagePath;
    }

    private function contentTypeForDerivedKind(string $kind, string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($kind) {
            'proxy_photo' => in_array($ext, ['webp'], true) ? 'image/webp' : 'image/jpeg',
            'proxy_audio' => in_array($ext, ['mp3'], true) ? 'audio/mpeg' : 'audio/mp4',
            default => 'video/mp4',
        };
    }
}
