<?php

namespace App\Command;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Ingest\Port\ScanStateStoreInterface;
use App\Ingest\Service\ProxyFileDetector;
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
        private ProxyFileDetector $proxyFileDetector,
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
            $sourcePath = ltrim((string) $file['path'], '/');
            if (!$this->isSafeRelativePath($sourcePath)) {
                $this->scanStateStore->markMissing($sourcePath, new \DateTimeImmutable());
                ++$missing;
                continue;
            }

            $absoluteSource = $root.DIRECTORY_SEPARATOR.$sourcePath;
            if (!is_file($absoluteSource)) {
                $this->scanStateStore->markMissing($sourcePath, new \DateTimeImmutable());
                ++$missing;
                continue;
            }

            $proxy = $this->proxyFileDetector->detectProxyFile($sourcePath);
            if ($proxy !== null) {
                $originalPath = (string) ($proxy['original'] ?? '');
                if ($originalPath !== '' && $this->isSafeRelativePath($originalPath)) {
                    $this->attachExistingProxyToAsset($originalPath, $proxy);
                    $queued += $this->enqueueRequiredJobs($this->findOrCreateAsset($originalPath), true);
                }
                $this->scanStateStore->markQueued($sourcePath, new \DateTimeImmutable());
                continue;
            }

            $asset = $this->findOrCreateAsset($sourcePath);

            $existingProxy = $this->proxyFileDetector->detectExistingProxyForOriginal($sourcePath);
            if ($existingProxy !== null) {
                $this->attachExistingProxyToAsset($sourcePath, $existingProxy);
            }

            $queued += $this->enqueueRequiredJobs($asset, $existingProxy !== null);

            $this->scanStateStore->markQueued($sourcePath, new \DateTimeImmutable());
        }

        $io->success(sprintf('Queued %d stable file(s). Missing: %d.', $queued, $missing));

        return Command::SUCCESS;
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

        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $sidecars = is_array($paths['sidecars_relative'] ?? null) ? $paths['sidecars_relative'] : [];
        if (!in_array($proxyPath, $sidecars, true)) {
            $sidecars[] = $proxyPath;
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
                && (string) ($item['ref'] ?? '') === $proxyPath
            ) {
                $alreadyInManifest = true;
                break;
            }
        }
        if (!$alreadyInManifest) {
            $manifest[] = [
                'kind' => $proxyKind,
                'ref' => $proxyPath,
            ];
        }

        $derived['derived_manifest'] = $manifest;
        $derived[sprintf('%s_url', $proxyKind)] = sprintf('/api/v1/assets/%s/derived/%s', $asset->getUuid(), $proxyKind);
        $fields['paths'] = $paths;
        $fields['derived'] = $derived;
        $fields['proxy_done'] = true;
        $asset->setFields($fields);
        $this->assets->save($asset);

        $this->persistDerivedFile($asset, $proxyKind, $proxyPath);
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
            if ($this->jobs->hasJobForAssetAndType($asset->getUuid(), $jobType)) {
                continue;
            }
            $this->jobs->enqueuePending($asset->getUuid(), $jobType);
            ++$queued;
        }

        return $queued;
    }

    private function persistDerivedFile(Asset $asset, string $kind, string $proxyPath): void
    {
        $existing = $this->connection->fetchAssociative(
            'SELECT id FROM asset_derived_file WHERE asset_uuid = :assetUuid AND kind = :kind ORDER BY created_at DESC LIMIT 1',
            ['assetUuid' => $asset->getUuid(), 'kind' => $kind]
        );
        if (is_array($existing)) {
            return;
        }

        $absolutePath = rtrim($this->watchPathResolver->resolveRoot(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$proxyPath;
        $size = is_file($absolutePath) ? filesize($absolutePath) : 0;
        $sha256 = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null;

        $this->connection->insert('asset_derived_file', [
            'id' => bin2hex(random_bytes(8)),
            'asset_uuid' => $asset->getUuid(),
            'kind' => $kind,
            'content_type' => $this->contentTypeForDerivedKind($kind, $proxyPath),
            'size_bytes' => is_int($size) ? $size : 0,
            'sha256' => is_string($sha256) ? $sha256 : null,
            'storage_path' => $proxyPath,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
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
