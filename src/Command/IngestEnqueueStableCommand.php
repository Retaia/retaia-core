<?php

namespace App\Command;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Ingest\Port\ScanStateStoreInterface;
use App\Ingest\Service\WatchPathResolver;
use App\Job\Repository\JobRepository;
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
        private AssetRepositoryInterface $assets,
        private JobRepository $jobs,
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

            $assetUuid = $this->assetUuidFromPath((string) $file['path']);
            $asset = $this->assets->findByUuid($assetUuid);
            if (!$asset instanceof Asset) {
                $asset = new Asset(
                    $assetUuid,
                    $this->mediaTypeFromPath((string) $file['path']),
                    basename((string) $file['path']),
                    AssetState::DISCOVERED,
                    [],
                    null,
                    [
                        'storage_id' => $this->defaultStorageId,
                        'source_path' => $sourcePath,
                        'paths' => [
                            'storage_id' => $this->defaultStorageId,
                            'original_relative' => $sourcePath,
                            'sidecars_relative' => [],
                        ],
                    ]
                );
                $this->assets->save($asset);
            }

            if (!$this->jobs->hasJobForAssetAndType($assetUuid, 'extract_facts')) {
                $this->jobs->enqueuePending($assetUuid, 'extract_facts');
                ++$queued;
            }

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
            'jpg', 'jpeg', 'png' => 'PHOTO',
            default => 'VIDEO',
        };
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        return !str_starts_with($path, '/') && !str_contains($path, '..'.DIRECTORY_SEPARATOR) && !str_contains($path, '../');
    }
}
