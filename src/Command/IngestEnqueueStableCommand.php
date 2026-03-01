<?php

namespace App\Command;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Ingest\Port\ScanStateStoreInterface;
use App\Ingest\Service\SidecarFileDetector;
use App\Ingest\Service\WatchPathResolver;
use App\Job\Repository\JobRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:ingest:enqueue-stable', description: 'Create assets/jobs from stable files detected in INBOX')]
final class IngestEnqueueStableCommand extends Command
{
    public function __construct(
        private ScanStateStoreInterface $scanStateStore,
        private WatchPathResolver $watchPathResolver,
        private SidecarFileDetector $sidecarFileDetector,
        private AssetRepositoryInterface $assets,
        private JobRepository $jobs,
        private Connection $connection,
        private string $defaultStorageId = 'nas-main',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum number of stable files to enqueue', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $stableFiles = $this->scanStateStore->listStableFiles($limit);
        $queued = 0;
        $missing = 0;
        $root = $this->watchPathResolver->resolveRoot();

        foreach ($stableFiles as $file) {
            try {
                $result = $this->connection->transactional(fn (): array => $this->processStableFile($file, $root));
                $queued += (int) ($result['queued'] ?? 0);
                $missing += (int) ($result['missing'] ?? 0);
            } catch (\Throwable $e) {
                $sourcePath = ltrim((string) ($file['path'] ?? ''), '/');
                if ($sourcePath !== '' && $this->isSafeRelativePath($sourcePath)) {
                    $this->scanStateStore->markMissing($sourcePath, new \DateTimeImmutable());
                }
                ++$missing;
                $io->warning(sprintf('Skipping ingest file %s: %s', $sourcePath !== '' ? $sourcePath : '<unknown>', $e->getMessage()));
            }
        }

        $io->success(sprintf('Queued %d stable file(s). Missing: %d.', $queued, $missing));

        return Command::SUCCESS;
    }

    /**
     * @param array{path:string,size:int,mtime:\DateTimeImmutable,stable_count:int,status:string} $file
     * @return array{queued:int,missing:int}
     */
    private function processStableFile(array $file, string $root): array
    {
        $queued = 0;
        $missing = 0;
        $sourcePath = ltrim((string) $file['path'], '/');
        if (!$this->isSafeRelativePath($sourcePath)) {
            $this->scanStateStore->markMissing($sourcePath, new \DateTimeImmutable());

            return ['queued' => 0, 'missing' => 1];
        }

        $absoluteSource = $root.DIRECTORY_SEPARATOR.$sourcePath;
        if (!is_file($absoluteSource)) {
            $missingProxy = $this->sidecarFileDetector->detectProxyFile($sourcePath);
            if ($missingProxy !== null) {
                $originalPath = (string) ($missingProxy['original'] ?? '');
                if ($originalPath !== '' && $this->isSafeRelativePath($originalPath) && $this->canUseExistingProxy($missingProxy, $originalPath)) {
                    $this->attachExistingProxyToAsset($originalPath, $missingProxy);
                    $queued += $this->enqueueRequiredJobs($this->findOrCreateAsset($originalPath), true);
                }
                $this->scanStateStore->markQueued($sourcePath, new \DateTimeImmutable());

                return ['queued' => $queued, 'missing' => 0];
            }

            $missingSidecar = $this->sidecarFileDetector->detectAuxiliarySidecarFile($sourcePath);
            if ($missingSidecar !== null) {
                $originalPath = (string) ($missingSidecar['original'] ?? '');
                $sidecarPath = (string) ($missingSidecar['path'] ?? '');
                if ($originalPath !== '' && $sidecarPath !== '' && $this->isSafeRelativePath($originalPath)) {
                    $this->attachAuxiliarySidecarToAsset($originalPath, $sidecarPath);
                    $queued += $this->enqueueRequiredJobs($this->findOrCreateAsset($originalPath), false);
                }
                $this->scanStateStore->markQueued($sourcePath, new \DateTimeImmutable());

                return ['queued' => $queued, 'missing' => 0];
            }
            if ($this->sidecarFileDetector->isAuxiliarySidecarPath($sourcePath)) {
                $this->scanStateStore->markQueued($sourcePath, new \DateTimeImmutable());

                return ['queued' => 0, 'missing' => 0];
            }

            $this->scanStateStore->markMissing($sourcePath, new \DateTimeImmutable());

            return ['queued' => 0, 'missing' => 1];
        }

        $proxy = $this->sidecarFileDetector->detectProxyFile($sourcePath);
        if ($proxy !== null) {
            $originalPath = (string) ($proxy['original'] ?? '');
            if ($originalPath !== '' && $this->isSafeRelativePath($originalPath) && $this->canUseExistingProxy($proxy, $originalPath)) {
                $this->attachExistingProxyToAsset($originalPath, $proxy);
                $queued += $this->enqueueRequiredJobs($this->findOrCreateAsset($originalPath), true);
            }
            $this->scanStateStore->markQueued($sourcePath, new \DateTimeImmutable());

            return ['queued' => $queued, 'missing' => 0];
        }

        $sidecar = $this->sidecarFileDetector->detectAuxiliarySidecarFile($sourcePath);
        if ($sidecar !== null) {
            $originalPath = (string) ($sidecar['original'] ?? '');
            $sidecarPath = (string) ($sidecar['path'] ?? '');
            if ($originalPath !== '' && $sidecarPath !== '' && $this->isSafeRelativePath($originalPath)) {
                $this->attachAuxiliarySidecarToAsset($originalPath, $sidecarPath);
                $queued += $this->enqueueRequiredJobs($this->findOrCreateAsset($originalPath), false);
            }
            $this->scanStateStore->markQueued($sourcePath, new \DateTimeImmutable());

            return ['queued' => $queued, 'missing' => 0];
        }
        if ($this->sidecarFileDetector->isAuxiliarySidecarPath($sourcePath)) {
            $this->scanStateStore->markQueued($sourcePath, new \DateTimeImmutable());

            return ['queued' => 0, 'missing' => 0];
        }

        $asset = $this->findOrCreateAsset($sourcePath);
        $this->attachExistingAuxiliarySidecarsToAsset($sourcePath);

        $existingProxy = $this->sidecarFileDetector->detectExistingProxyForOriginal($sourcePath);
        $usableExistingProxy = $existingProxy !== null && $this->canUseExistingProxy($existingProxy, $sourcePath);
        if ($usableExistingProxy && $existingProxy !== null) {
            $this->attachExistingProxyToAsset($sourcePath, $existingProxy);
        }

        $queued += $this->enqueueRequiredJobs($asset, $usableExistingProxy);
        $this->scanStateStore->markQueued($sourcePath, new \DateTimeImmutable());

        return ['queued' => $queued, 'missing' => $missing];
    }

    private function assetUuidFromPath(string $path): string
    {
        $hex = md5($path);

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

    private function findOrCreateAsset(string $sourcePath): Asset
    {
        $assetUuid = $this->assetUuidFromPath($sourcePath);
        $asset = $this->assets->findByUuid($assetUuid);
        if ($asset instanceof Asset) {
            return $asset;
        }

        $asset = new Asset(
            $assetUuid,
            $this->mediaTypeFromPath($sourcePath),
            basename($sourcePath),
            AssetState::DISCOVERED,
            [],
            null,
            [
                'storage_id' => $this->defaultStorageId,
                'source_path' => $sourcePath,
                'processing_profile' => $this->processingProfileFromMediaType($this->mediaTypeFromPath($sourcePath)),
                'paths' => [
                    'storage_id' => $this->defaultStorageId,
                    'original_relative' => $sourcePath,
                    'sidecars_relative' => [],
                ],
            ]
        );
        $this->assets->save($asset);

        return $asset;
    }

    /**
     * @param array{path:string,type:string,kind:string,original:string} $proxy
     */
    private function attachExistingProxyToAsset(string $originalPath, array $proxy): void
    {
        $asset = $this->findOrCreateAsset($originalPath);
        $fields = $asset->getFields();

        $proxyPath = (string) ($proxy['path'] ?? '');
        $proxyKind = (string) ($proxy['kind'] ?? '');
        if ($proxyPath === '' || $proxyKind === '') {
            return;
        }

        $materializedStoragePath = $this->persistDerivedFile($asset, $proxyKind, $proxyPath);

        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $sidecars = is_array($paths['sidecars_relative'] ?? null) ? $paths['sidecars_relative'] : [];
        $sidecars = array_values(array_filter(array_map('strval', $sidecars), static fn (string $item): bool => $item !== $proxyPath && $item !== ''));
        if (!in_array($materializedStoragePath, $sidecars, true)) {
            $sidecars[] = $materializedStoragePath;
        }
        $paths['storage_id'] = (string) ($paths['storage_id'] ?? $this->defaultStorageId);
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

    private function attachAuxiliarySidecarToAsset(string $originalPath, string $sidecarPath): void
    {
        $asset = $this->findOrCreateAsset($originalPath);
        $fields = $asset->getFields();

        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $sidecars = is_array($paths['sidecars_relative'] ?? null) ? $paths['sidecars_relative'] : [];
        $sidecars = array_values(array_filter(array_map('strval', $sidecars), static fn (string $item): bool => $item !== ''));
        if (!in_array($sidecarPath, $sidecars, true)) {
            $sidecars[] = $sidecarPath;
        }

        $paths['storage_id'] = (string) ($paths['storage_id'] ?? $this->defaultStorageId);
        $paths['original_relative'] = (string) ($paths['original_relative'] ?? $originalPath);
        $paths['sidecars_relative'] = array_values(array_unique($sidecars));
        $fields['paths'] = $paths;
        $asset->setFields($fields);
        $this->assets->save($asset);
    }

    private function attachExistingAuxiliarySidecarsToAsset(string $originalPath): void
    {
        $sidecars = $this->sidecarFileDetector->detectExistingAuxiliarySidecarsForOriginal($originalPath);
        foreach ($sidecars as $sidecarPath) {
            if ($this->isSafeRelativePath($sidecarPath)) {
                $this->attachAuxiliarySidecarToAsset($originalPath, $sidecarPath);
            }
        }
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        return !str_starts_with($path, '/') && !str_contains($path, '..'.DIRECTORY_SEPARATOR) && !str_contains($path, '../');
    }

    private function processingProfileFromMediaType(string $mediaType): string
    {
        return match ($mediaType) {
            'PHOTO' => 'photo_standard',
            'AUDIO' => 'audio_music',
            default => 'video_standard',
        };
    }

    private function enqueueRequiredJobs(Asset $asset, bool $hasExistingProxy): int
    {
        $queued = 0;
        $jobs = ['extract_facts', 'generate_thumbnails'];
        if (!$hasExistingProxy) {
            $jobs[] = 'generate_proxy';
        }
        if ($asset->getMediaType() === 'AUDIO') {
            $jobs[] = 'generate_audio_waveform';
        }

        foreach ($jobs as $jobType) {
            if ($this->jobs->enqueuePendingIfMissing($asset->getUuid(), $jobType)) {
                ++$queued;
            }
        }

        return $queued;
    }

    private function persistDerivedFile(Asset $asset, string $kind, string $proxyPath): string
    {
        $existing = $this->connection->fetchAssociative(
            'SELECT id, storage_path FROM asset_derived_file WHERE asset_uuid = :assetUuid AND kind = :kind ORDER BY created_at DESC LIMIT 1',
            ['assetUuid' => $asset->getUuid(), 'kind' => $kind]
        );

        $root = rtrim($this->watchPathResolver->resolveRoot(), DIRECTORY_SEPARATOR);
        $storagePath = $this->materializeExistingProxyToDerived($root, $asset->getUuid(), $kind, $proxyPath);
        $absolutePath = $root.DIRECTORY_SEPARATOR.$storagePath;
        $size = is_file($absolutePath) ? filesize($absolutePath) : 0;
        $sha256 = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null;

        if (is_array($existing)) {
            $existingStoragePath = (string) ($existing['storage_path'] ?? '');
            if ($existingStoragePath !== $storagePath) {
                $this->connection->update('asset_derived_file', [
                    'storage_path' => $storagePath,
                    'content_type' => $this->contentTypeForDerivedKind($kind, $storagePath),
                    'size_bytes' => is_int($size) ? $size : 0,
                    'sha256' => is_string($sha256) ? $sha256 : null,
                ], [
                    'id' => (string) $existing['id'],
                ]);
            }

            return $storagePath;
        }

        $this->connection->insert('asset_derived_file', [
            'id' => bin2hex(random_bytes(8)),
            'asset_uuid' => $asset->getUuid(),
            'kind' => $kind,
            'content_type' => $this->contentTypeForDerivedKind($kind, $storagePath),
            'size_bytes' => is_int($size) ? $size : 0,
            'sha256' => is_string($sha256) ? $sha256 : null,
            'storage_path' => $storagePath,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

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

    /**
     * @param array{path:string,type:string,kind:string,original:string} $proxy
     */
    private function canUseExistingProxy(array $proxy, string $originalPath): bool
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

        $assetUuid = $this->assetUuidFromPath($originalPath);
        $existing = $this->connection->fetchAssociative(
            'SELECT storage_path FROM asset_derived_file WHERE asset_uuid = :assetUuid AND kind = :kind ORDER BY created_at DESC LIMIT 1',
            ['assetUuid' => $assetUuid, 'kind' => $kind]
        );
        if (!is_array($existing)) {
            return false;
        }

        $storagePath = (string) ($existing['storage_path'] ?? '');
        if ($storagePath === '') {
            return false;
        }

        $derivedAbsolutePath = $root.DIRECTORY_SEPARATOR.$storagePath;
        if (!is_file($derivedAbsolutePath)) {
            return false;
        }

        $derivedSize = filesize($derivedAbsolutePath);

        return is_int($derivedSize) && $derivedSize > 0;
    }
}
