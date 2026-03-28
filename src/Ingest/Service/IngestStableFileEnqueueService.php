<?php

namespace App\Ingest\Service;

use App\Entity\Asset;
use App\Ingest\Port\ScanStateStoreInterface;
use App\Ingest\Repository\IngestDiagnosticsRepository;
use App\Storage\BusinessStorageRegistryInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class IngestStableFileEnqueueService
{
    public function __construct(
        private ScanStateStoreInterface $scanStateStore,
        private Connection $connection,
        private BusinessStorageRegistryInterface $storageRegistry,
        private BusinessStorageAwareSidecarLocator $sidecars,
        private IngestDiagnosticsRepository $ingestDiagnostics,
        private ExistingProxyAttachmentService $existingProxyAttachment,
        private IngestAssetService $assets,
        private IngestJobEnqueuer $jobs,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{queued:int,missing:int,unmatched_sidecars:int}
     */
    public function enqueueStableFiles(int $limit): array
    {
        $stableFiles = $this->scanStateStore->listStableFiles($limit);
        $summary = ['queued' => 0, 'missing' => 0, 'unmatched_sidecars' => 0];

        foreach ($stableFiles as $file) {
            try {
                $result = $this->connection->transactional(fn (): array => $this->processStableFile($file));
                $summary['queued'] += (int) ($result['queued'] ?? 0);
                $summary['missing'] += (int) ($result['missing'] ?? 0);
                $summary['unmatched_sidecars'] += (int) ($result['unmatched_sidecars'] ?? 0);
            } catch (\Throwable $e) {
                $storageId = trim((string) ($file['storage_id'] ?? ''));
                $sourcePath = ltrim((string) ($file['path'] ?? ''), '/');
                if ($sourcePath !== '' && $this->isSafeRelativePath($sourcePath)) {
                    $this->scanStateStore->markMissing($storageId, $sourcePath, new \DateTimeImmutable());
                }
                ++$summary['missing'];
                $this->logger->warning('ingest.file.skipped', [
                    'storage_id' => $storageId,
                    'path' => $sourcePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * @param array{storage_id:string,path:string,size:int,mtime:\DateTimeImmutable,stable_count:int,status:string} $file
     * @return array{queued:int,missing:int,unmatched_sidecars:int}
     */
    private function processStableFile(array $file): array
    {
        $queued = 0;
        $storageId = trim((string) ($file['storage_id'] ?? ''));
        $sourcePath = ltrim((string) $file['path'], '/');
        $storage = $this->sidecarsStorage($storageId);
        if (!$this->isSafeRelativePath($sourcePath)) {
            $this->scanStateStore->markMissing($storageId, $sourcePath, new \DateTimeImmutable());

            return ['queued' => 0, 'missing' => 1, 'unmatched_sidecars' => 0];
        }

        if (!$storage->fileExists($sourcePath)) {
            $sidecarOrProxyResult = $this->handleSidecarOrProxyCandidate($storageId, $sourcePath, $queued);
            if ($sidecarOrProxyResult !== null) {
                return $sidecarOrProxyResult;
            }

            $this->scanStateStore->markMissing($storageId, $sourcePath, new \DateTimeImmutable());

            return ['queued' => 0, 'missing' => 1, 'unmatched_sidecars' => 0];
        }

        $sidecarOrProxyResult = $this->handleSidecarOrProxyCandidate($storageId, $sourcePath, $queued);
        if ($sidecarOrProxyResult !== null) {
            return $sidecarOrProxyResult;
        }

        $asset = $this->assets->findOrCreateAsset($storageId, $sourcePath);
        $this->assets->attachExistingAuxiliarySidecarsToAsset($storageId, $sourcePath);

        $existingProxy = $this->sidecars->detectExistingProxyForOriginal($storageId, $sourcePath);
        $usableExistingProxy = false;
        if ($existingProxy !== null && $this->assets->assetStorageMatches($asset, $storageId, (string) ($existingProxy['path'] ?? ''))) {
            $usableExistingProxy = $this->existingProxyAttachment->canUse($storageId, $existingProxy, $asset->getUuid());
        }
        if ($usableExistingProxy && $existingProxy !== null) {
            $this->existingProxyAttachment->attachToAsset($asset, $storageId, $sourcePath, $existingProxy);
        }

        $queued += $this->jobs->enqueueRequiredJobs($asset, $usableExistingProxy);
        $this->scanStateStore->markQueued($storageId, $sourcePath, new \DateTimeImmutable());

        return ['queued' => $queued, 'missing' => 0, 'unmatched_sidecars' => 0];
    }

    /**
     * @param int $queued
     * @return array{queued:int,missing:int,unmatched_sidecars:int}|null
     */
    private function handleSidecarOrProxyCandidate(string $storageId, string $sourcePath, int &$queued): ?array
    {
        $proxy = $this->sidecars->detectProxyFile($storageId, $sourcePath);
        if ($proxy !== null) {
            $originalPath = (string) ($proxy['original'] ?? '');
            if ($originalPath !== '' && $this->isSafeRelativePath($originalPath)) {
                $asset = $this->assets->findOrCreateAsset($storageId, $originalPath);
                if (!$this->assets->assetStorageMatches($asset, $storageId, (string) ($proxy['path'] ?? ''))) {
                    $this->scanStateStore->markQueued($storageId, $sourcePath, new \DateTimeImmutable());

                    return ['queued' => 0, 'missing' => 0, 'unmatched_sidecars' => 1];
                }
                if ($this->existingProxyAttachment->canUse($storageId, $proxy, $asset->getUuid())) {
                    $this->existingProxyAttachment->attachToAsset($asset, $storageId, $originalPath, $proxy);
                    $queued += $this->jobs->enqueueRequiredJobs($asset, true);
                }
            }
            $this->scanStateStore->markQueued($storageId, $sourcePath, new \DateTimeImmutable());

            return ['queued' => $queued, 'missing' => 0, 'unmatched_sidecars' => 0];
        }

        if ($this->sidecars->isProxyCandidatePath($sourcePath)) {
            $this->ingestDiagnostics->recordUnmatchedSidecar($sourcePath, 'missing_parent');
            $this->scanStateStore->markQueued($storageId, $sourcePath, new \DateTimeImmutable());

            return ['queued' => 0, 'missing' => 0, 'unmatched_sidecars' => 1];
        }

        return $this->handleAuxiliarySidecarCandidate($storageId, $sourcePath, $queued);
    }

    /**
     * @param int $queued
     * @return array{queued:int,missing:int,unmatched_sidecars:int}|null
     */
    private function handleAuxiliarySidecarCandidate(string $storageId, string $sourcePath, int &$queued): ?array
    {
        $sidecar = $this->sidecars->detectAuxiliarySidecarFile($storageId, $sourcePath);
        if ($sidecar !== null) {
            $originalPath = (string) ($sidecar['original'] ?? '');
            $sidecarPath = (string) ($sidecar['path'] ?? '');
            if ($originalPath !== '' && $sidecarPath !== '' && $this->isSafeRelativePath($originalPath)) {
                if (!$this->assets->attachAuxiliarySidecarToAsset($storageId, $originalPath, $sidecarPath)) {
                    $this->scanStateStore->markQueued($storageId, $sourcePath, new \DateTimeImmutable());

                    return ['queued' => 0, 'missing' => 0, 'unmatched_sidecars' => 1];
                }
                $queued += $this->jobs->enqueueRequiredJobs($this->assets->findOrCreateAsset($storageId, $originalPath), false);
            }
            $this->scanStateStore->markQueued($storageId, $sourcePath, new \DateTimeImmutable());
            $this->ingestDiagnostics->clearUnmatchedSidecar($sourcePath);

            return ['queued' => $queued, 'missing' => 0, 'unmatched_sidecars' => 0];
        }

        if ($this->sidecars->isAuxiliarySidecarPath($sourcePath)) {
            $reason = $this->sidecars->auxiliaryUnmatchedReason($storageId, $sourcePath) ?? 'missing_parent';
            $this->ingestDiagnostics->recordUnmatchedSidecar($sourcePath, $reason);
            $this->scanStateStore->markQueued($storageId, $sourcePath, new \DateTimeImmutable());

            return ['queued' => 0, 'missing' => 0, 'unmatched_sidecars' => 1];
        }

        return null;
    }

    private function sidecarsStorage(string $storageId): \App\Storage\BusinessStorageInterface
    {
        return $this->storageRegistry->get($storageId)->storage;
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        return !str_starts_with($path, '/') && !str_contains($path, '..\\') && !str_contains($path, '../');
    }
}
