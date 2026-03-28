<?php

namespace App\Command;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Ingest\Port\ScanStateStoreInterface;
use App\Ingest\Repository\IngestDiagnosticsRepository;
use App\Ingest\Service\ExistingProxyAttachmentService;
use App\Ingest\Service\SidecarFileDetector;
use App\Ingest\Service\WatchPathResolver;
use App\Job\Repository\JobRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
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
        private IngestDiagnosticsRepository $ingestDiagnostics,
        private ExistingProxyAttachmentService $existingProxyAttachment,
        private AssetRepositoryInterface $assets,
        private JobRepository $jobs,
        private Connection $connection,
        private LoggerInterface $logger,
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
        $unmatchedSidecars = 0;
        $root = $this->watchPathResolver->resolveRoot();

        foreach ($stableFiles as $file) {
            try {
                $result = $this->connection->transactional(fn (): array => $this->processStableFile($file, $root));
                $queued += (int) ($result['queued'] ?? 0);
                $missing += (int) ($result['missing'] ?? 0);
                $unmatchedSidecars += (int) ($result['unmatched_sidecars'] ?? 0);
            } catch (\Throwable $e) {
                $sourcePath = ltrim((string) ($file['path'] ?? ''), '/');
                if ($sourcePath !== '' && $this->isSafeRelativePath($sourcePath)) {
                    $this->scanStateStore->markMissing($sourcePath, new \DateTimeImmutable());
                }
                ++$missing;
                $io->warning(sprintf('Skipping ingest file %s: %s', $sourcePath !== '' ? $sourcePath : '<unknown>', $e->getMessage()));
            }
        }

        $io->success(sprintf('Queued %d stable file(s). Missing: %d. Unmatched sidecars: %d.', $queued, $missing, $unmatchedSidecars));

        return Command::SUCCESS;
    }

    /**
     * @param array{path:string,size:int,mtime:\DateTimeImmutable,stable_count:int,status:string} $file
     * @return array{queued:int,missing:int,unmatched_sidecars:int}
     */
    private function processStableFile(array $file, string $root): array
    {
        $queued = 0;
        $missing = 0;
        $unmatchedSidecars = 0;
        $sourcePath = ltrim((string) $file['path'], '/');
        if (!$this->isSafeRelativePath($sourcePath)) {
            $this->scanStateStore->markMissing($sourcePath, new \DateTimeImmutable());

            return ['queued' => 0, 'missing' => 1, 'unmatched_sidecars' => 0];
        }

        $absoluteSource = $root.DIRECTORY_SEPARATOR.$sourcePath;
        if (!is_file($absoluteSource)) {
            $sidecarOrProxyResult = $this->handleSidecarOrProxyCandidate($sourcePath, $queued);
            if ($sidecarOrProxyResult !== null) {
                return $sidecarOrProxyResult;
            }

            $this->scanStateStore->markMissing($sourcePath, new \DateTimeImmutable());

            return ['queued' => 0, 'missing' => 1, 'unmatched_sidecars' => 0];
        }

        $sidecarOrProxyResult = $this->handleSidecarOrProxyCandidate($sourcePath, $queued);
        if ($sidecarOrProxyResult !== null) {
            return $sidecarOrProxyResult;
        }

        $asset = $this->findOrCreateAsset($sourcePath);
        $this->attachExistingAuxiliarySidecarsToAsset($sourcePath);

        $existingProxy = $this->sidecarFileDetector->detectExistingProxyForOriginal($sourcePath);
        $usableExistingProxy = $existingProxy !== null && $this->existingProxyAttachment->canUse($existingProxy, $asset->getUuid());
        if ($usableExistingProxy && $existingProxy !== null) {
            $this->existingProxyAttachment->attachToAsset($asset, $sourcePath, $existingProxy, $this->defaultStorageId);
        }

        $queued += $this->enqueueRequiredJobs($asset, $usableExistingProxy);
        $this->scanStateStore->markQueued($sourcePath, new \DateTimeImmutable());

        return ['queued' => $queued, 'missing' => $missing, 'unmatched_sidecars' => $unmatchedSidecars];
    }

    /**
     * @param int $queued
     * @return array{queued:int,missing:int,unmatched_sidecars:int}|null
     */
    private function handleSidecarOrProxyCandidate(string $sourcePath, int &$queued): ?array
    {
        $proxy = $this->sidecarFileDetector->detectProxyFile($sourcePath);
        if ($proxy !== null) {
            $originalPath = (string) ($proxy['original'] ?? '');
            if ($originalPath !== '' && $this->isSafeRelativePath($originalPath)) {
                $asset = $this->findOrCreateAsset($originalPath);
                if ($this->existingProxyAttachment->canUse($proxy, $asset->getUuid())) {
                    $this->existingProxyAttachment->attachToAsset($asset, $originalPath, $proxy, $this->defaultStorageId);
                    $queued += $this->enqueueRequiredJobs($asset, true);
                }
            }
            $this->scanStateStore->markQueued($sourcePath, new \DateTimeImmutable());

            return ['queued' => $queued, 'missing' => 0, 'unmatched_sidecars' => 0];
        }

        if ($this->sidecarFileDetector->isProxyCandidatePath($sourcePath)) {
            $this->ingestDiagnostics->recordUnmatchedSidecar($sourcePath, 'missing_parent');
            $this->scanStateStore->markQueued($sourcePath, new \DateTimeImmutable());

            return ['queued' => 0, 'missing' => 0, 'unmatched_sidecars' => 1];
        }

        return $this->handleAuxiliarySidecarCandidate($sourcePath, $queued);
    }

    /**
     * @param int $queued
     * @return array{queued:int,missing:int,unmatched_sidecars:int}|null
     */
    private function handleAuxiliarySidecarCandidate(string $sourcePath, int &$queued): ?array
    {
        $sidecar = $this->sidecarFileDetector->detectAuxiliarySidecarFile($sourcePath);
        if ($sidecar !== null) {
            $originalPath = (string) ($sidecar['original'] ?? '');
            $sidecarPath = (string) ($sidecar['path'] ?? '');
            if ($originalPath !== '' && $sidecarPath !== '' && $this->isSafeRelativePath($originalPath)) {
                $this->attachAuxiliarySidecarToAsset($originalPath, $sidecarPath);
                $queued += $this->enqueueRequiredJobs($this->findOrCreateAsset($originalPath), false);
            }
            $this->scanStateStore->markQueued($sourcePath, new \DateTimeImmutable());
            $this->ingestDiagnostics->clearUnmatchedSidecar($sourcePath);

            return ['queued' => $queued, 'missing' => 0, 'unmatched_sidecars' => 0];
        }

        if ($this->sidecarFileDetector->isAuxiliarySidecarPath($sourcePath)) {
            $reason = $this->sidecarFileDetector->auxiliaryUnmatchedReason($sourcePath) ?? 'missing_parent';
            $this->ingestDiagnostics->recordUnmatchedSidecar($sourcePath, $reason);
            $this->scanStateStore->markQueued($sourcePath, new \DateTimeImmutable());

            return ['queued' => 0, 'missing' => 0, 'unmatched_sidecars' => 1];
        }

        return null;
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
                'review_processing_version' => '1',
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
        $this->ingestDiagnostics->clearUnmatchedSidecar($sidecarPath);

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
        $stateVersion = $this->ensureReviewProcessingVersion($asset);
        $ingestCorrelationId = $this->newIngestCorrelationId($asset->getUuid());
        $jobs = ['extract_facts', 'generate_thumbnails'];
        if (!$hasExistingProxy) {
            $jobs[] = 'generate_preview';
        }
        if ($asset->getMediaType() === 'AUDIO') {
            $jobs[] = 'generate_audio_waveform';
        }

        foreach ($jobs as $jobType) {
            if ($this->jobs->enqueuePendingIfMissing($asset->getUuid(), $jobType, $stateVersion, $ingestCorrelationId)) {
                ++$queued;
                $this->logger->info('ingest.job.queued', [
                    'correlation_id' => $ingestCorrelationId,
                    'asset_uuid' => $asset->getUuid(),
                    'job_type' => $jobType,
                ]);
            } else {
                $this->logger->info('ingest.job.deduplicated', [
                    'correlation_id' => $ingestCorrelationId,
                    'asset_uuid' => $asset->getUuid(),
                    'job_type' => $jobType,
                ]);
            }
        }

        return $queued;
    }

    private function ensureReviewProcessingVersion(Asset $asset): string
    {
        $fields = $asset->getFields();
        $current = trim((string) ($fields['review_processing_version'] ?? ''));
        if ($current !== '') {
            return $current;
        }

        $fields['review_processing_version'] = '1';
        $asset->setFields($fields);
        $this->assets->save($asset);

        return '1';
    }

    private function newIngestCorrelationId(string $assetUuid): string
    {
        return sprintf('ing-%s', substr(hash('sha256', $assetUuid.'|'.bin2hex(random_bytes(8))), 0, 24));
    }

}
