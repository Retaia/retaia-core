<?php

namespace App\Ingest\Service;

use App\Asset\Repository\AssetRepositoryInterface;
use App\Derived\DerivedFileRepositoryInterface;
use App\Entity\Asset;
use App\Ingest\Repository\IngestDiagnosticsRepository;

final class ExistingProxyAttachmentService
{
    public function __construct(
        private WatchPathResolver $watchPathResolver,
        private IngestDiagnosticsRepository $ingestDiagnostics,
        private AssetRepositoryInterface $assets,
        private DerivedFileRepositoryInterface $derivedFiles,
    ) {
    }

    /**
     * @param array{path:string,type:string,kind:string,original:string} $proxy
     */
    public function canUse(array $proxy, string $assetUuid): bool
    {
        $kind = (string) ($proxy['kind'] ?? '');
        $path = (string) ($proxy['path'] ?? '');
        if ($kind === '' || $path === '') {
            return false;
        }

        $root = rtrim($this->watchPathResolver->resolveRoot(), DIRECTORY_SEPARATOR);
        $absolutePath = $root.DIRECTORY_SEPARATOR.$path;
        if (is_file($absolutePath)) {
            $size = filesize($absolutePath);
            if (is_int($size) && $size > 0) {
                return true;
            }
        }

        $existing = $this->derivedFiles->findLatestByAssetAndKind($assetUuid, $kind);
        if ($existing === null) {
            return false;
        }

        $derivedAbsolutePath = $root.DIRECTORY_SEPARATOR.$existing->storagePath;
        if (!is_file($derivedAbsolutePath)) {
            return false;
        }

        $derivedSize = filesize($derivedAbsolutePath);

        return is_int($derivedSize) && $derivedSize > 0;
    }

    /**
     * @param array{path:string,type:string,kind:string,original:string} $proxy
     */
    public function attachToAsset(Asset $asset, string $originalPath, array $proxy, string $defaultStorageId): void
    {
        $fields = $asset->getFields();

        $proxyPath = (string) ($proxy['path'] ?? '');
        $proxyKind = (string) ($proxy['kind'] ?? '');
        if ($proxyPath === '' || $proxyKind === '') {
            return;
        }
        $this->ingestDiagnostics->clearUnmatchedSidecar($proxyPath);

        $materializedStoragePath = $this->persistDerivedFile($asset->getUuid(), $proxyKind, $proxyPath);

        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $sidecars = is_array($paths['sidecars_relative'] ?? null) ? $paths['sidecars_relative'] : [];
        $sidecars = array_values(array_filter(array_map('strval', $sidecars), static fn (string $item): bool => $item !== $proxyPath && $item !== ''));
        if (!in_array($materializedStoragePath, $sidecars, true)) {
            $sidecars[] = $materializedStoragePath;
        }
        $paths['storage_id'] = (string) ($paths['storage_id'] ?? $defaultStorageId);
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

    private function persistDerivedFile(string $assetUuid, string $kind, string $proxyPath): string
    {
        $root = rtrim($this->watchPathResolver->resolveRoot(), DIRECTORY_SEPARATOR);
        $storagePath = $this->materializeExistingProxyToDerived($root, $assetUuid, $kind, $proxyPath);
        $absolutePath = $root.DIRECTORY_SEPARATOR.$storagePath;
        $size = is_file($absolutePath) ? filesize($absolutePath) : 0;
        $sha256 = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null;

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

    private function materializeExistingProxyToDerived(string $root, string $assetUuid, string $kind, string $proxyPath): string
    {
        $baseName = pathinfo($proxyPath, PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($proxyPath, PATHINFO_EXTENSION));
        if ($extension === 'lrf') {
            $extension = 'mp4';
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $baseName) ?: $kind;
        $targetFileName = $safeName.'.'.$extension;
        $targetStoragePath = sprintf('.derived/%s/%s', $assetUuid, $targetFileName);
        $targetAbsolutePath = $root.DIRECTORY_SEPARATOR.$targetStoragePath;

        if (!is_dir(dirname($targetAbsolutePath)) && !mkdir(dirname($targetAbsolutePath), 0777, true) && !is_dir(dirname($targetAbsolutePath))) {
            throw new \RuntimeException(sprintf('Unable to create derived directory for %s', $targetStoragePath));
        }

        if (is_file($targetAbsolutePath)) {
            return $targetStoragePath;
        }

        $sourceAbsolutePath = $root.DIRECTORY_SEPARATOR.$proxyPath;
        if (!is_file($sourceAbsolutePath)) {
            throw new \RuntimeException(sprintf('Proxy source file not found: %s', $proxyPath));
        }

        if (@rename($sourceAbsolutePath, $targetAbsolutePath)) {
            return $targetStoragePath;
        }

        if (!@copy($sourceAbsolutePath, $targetAbsolutePath)) {
            throw new \RuntimeException(sprintf('Unable to move proxy %s into %s', $proxyPath, $targetStoragePath));
        }
        @unlink($sourceAbsolutePath);

        return $targetStoragePath;
    }
}
