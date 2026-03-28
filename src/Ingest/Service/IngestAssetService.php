<?php

namespace App\Ingest\Service;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Ingest\Repository\IngestDiagnosticsRepository;
use Psr\Log\LoggerInterface;

final class IngestAssetService
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private BusinessStorageAwareSidecarLocator $sidecars,
        private IngestDiagnosticsRepository $ingestDiagnostics,
        private LoggerInterface $logger,
    ) {
    }

    public function findOrCreateAsset(string $storageId, string $sourcePath): Asset
    {
        $assetUuid = $this->assetUuidFromPath($storageId, $sourcePath);
        $asset = $this->assets->findByUuid($assetUuid);
        if ($asset instanceof Asset) {
            return $asset;
        }

        $mediaType = $this->mediaTypeFromPath($sourcePath);
        $asset = new Asset(
            $assetUuid,
            $mediaType,
            basename($sourcePath),
            AssetState::DISCOVERED,
            [],
            null,
            [
                'review_processing_version' => '1',
                'processing_profile' => $this->processingProfileFromMediaType($mediaType),
                'paths' => [
                    'storage_id' => $storageId,
                    'original_relative' => $sourcePath,
                    'sidecars_relative' => [],
                ],
            ]
        );
        $this->assets->save($asset);

        return $asset;
    }

    public function attachAuxiliarySidecarToAsset(string $storageId, string $originalPath, string $sidecarPath): bool
    {
        $asset = $this->findOrCreateAsset($storageId, $originalPath);
        if (!$this->assetStorageMatches($asset, $storageId, $sidecarPath)) {
            return false;
        }
        $fields = $asset->getFields();

        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $sidecars = is_array($paths['sidecars_relative'] ?? null) ? $paths['sidecars_relative'] : [];
        $sidecars = array_values(array_filter(array_map('strval', $sidecars), static fn (string $item): bool => $item !== ''));
        if (!in_array($sidecarPath, $sidecars, true)) {
            $sidecars[] = $sidecarPath;
        }
        $this->ingestDiagnostics->clearUnmatchedSidecar($sidecarPath);

        $paths['storage_id'] = $storageId;
        $paths['original_relative'] = $originalPath;
        $paths['sidecars_relative'] = array_values(array_unique($sidecars));
        $fields['paths'] = $paths;
        $asset->setFields($fields);
        $this->assets->save($asset);

        return true;
    }

    public function attachExistingAuxiliarySidecarsToAsset(string $storageId, string $originalPath): void
    {
        foreach ($this->sidecars->existingAuxiliarySidecarsForOriginal($storageId, $originalPath) as $sidecarPath) {
            $this->attachAuxiliarySidecarToAsset($storageId, $originalPath, $sidecarPath);
        }
    }

    public function assetStorageMatches(Asset $asset, string $storageId, string $relatedPath): bool
    {
        $fields = $asset->getFields();
        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $assetStorageId = trim((string) ($paths['storage_id'] ?? ''));
        if ($assetStorageId === '' || $assetStorageId === $storageId) {
            return true;
        }

        if ($relatedPath !== '') {
            $this->ingestDiagnostics->recordUnmatchedSidecar($relatedPath, 'storage_mismatch');
        }

        $this->logger->warning('ingest.sidecar.storage_mismatch', [
            'asset_uuid' => $asset->getUuid(),
            'asset_storage_id' => $assetStorageId,
            'sidecar_storage_id' => $storageId,
            'related_path' => $relatedPath,
        ]);

        return false;
    }

    private function assetUuidFromPath(string $storageId, string $path): string
    {
        $hex = md5($storageId.'|'.$path);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    private function mediaTypeFromPath(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'wav', 'mp3', 'aac' => 'AUDIO',
            'jpg', 'jpeg', 'png', 'webp', 'cr2', 'cr3', 'nef', 'arw', 'dng', 'rw2', 'orf', 'raf' => 'PHOTO',
            default => 'VIDEO',
        };
    }

    private function processingProfileFromMediaType(string $mediaType): string
    {
        return match ($mediaType) {
            'PHOTO' => 'photo_standard',
            'AUDIO' => 'audio_music',
            default => 'video_standard',
        };
    }
}
