<?php

namespace App\Command;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Ingest\Repository\PathAuditRepository;
use App\Ingest\Service\WatchPathResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:ingest:apply-outbox', description: 'Move ARCHIVED/REJECTED files from INBOX and audit path history')]
final class IngestApplyOutboxCommand extends Command
{
    public function __construct(
        private WatchPathResolver $watchPathResolver,
        private AssetRepositoryInterface $assets,
        private PathAuditRepository $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum number of assets to process', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $processed = 0;

        $assets = array_merge(
            $this->assets->listAssets(AssetState::ARCHIVED->value, null, null, $limit),
            $this->assets->listAssets(AssetState::REJECTED->value, null, null, $limit),
        );

        foreach ($assets as $asset) {
            $processed += $this->processAsset($asset);
        }

        $io->success(sprintf('Moved %d file(s) to ARCHIVE/REJECTS.', $processed));

        return Command::SUCCESS;
    }

    private function processAsset(Asset $asset): int
    {
        $fields = $asset->getFields();
        $sourcePath = (string) ($fields['source_path'] ?? '');
        if ($sourcePath === '') {
            return 0;
        }
        if (!$this->isSafeRelativePath($sourcePath)) {
            return 0;
        }

        $root = $this->watchPathResolver->resolveRoot();
        $fromRelative = ltrim($sourcePath, '/');
        $fromAbsolute = $root.DIRECTORY_SEPARATOR.$fromRelative;
        $targetFolder = $asset->getState() === AssetState::ARCHIVED ? 'ARCHIVE' : 'REJECTS';
        $targetRelative = $targetFolder.DIRECTORY_SEPARATOR.basename($fromRelative);
        $targetAbsolute = $root.DIRECTORY_SEPARATOR.$targetRelative;

        if (!is_dir(dirname($targetAbsolute))) {
            mkdir(dirname($targetAbsolute), 0777, true);
        }

        if (is_file($fromAbsolute)) {
            $finalAbsolute = $targetAbsolute;
            $finalRelative = $targetRelative;
            if (is_file($targetAbsolute)) {
                $ext = pathinfo($targetAbsolute, PATHINFO_EXTENSION);
                $name = pathinfo($targetAbsolute, PATHINFO_FILENAME);
                $suffix = substr(str_replace('-', '', $asset->getUuid()), 0, 6);
                $filename = $ext === '' ? sprintf('%s__%s', $name, $suffix) : sprintf('%s__%s.%s', $name, $suffix, $ext);
                $finalRelative = $targetFolder.DIRECTORY_SEPARATOR.$filename;
                $finalAbsolute = $root.DIRECTORY_SEPARATOR.$finalRelative;
            }
            rename($fromAbsolute, $finalAbsolute);
            $this->persistPathUpdate($asset, $fromRelative, $finalRelative);

            return 1;
        }

        if (is_file($targetAbsolute)) {
            $this->persistPathUpdate($asset, $fromRelative, $targetRelative);

            return 0;
        }

        return 0;
    }

    private function persistPathUpdate(Asset $asset, string $fromRelative, string $toRelative): void
    {
        $fields = $asset->getFields();
        $history = $fields['path_history'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }

        $entry = [
            'from' => $fromRelative,
            'to' => $toRelative,
            'reason' => 'state_transition',
            'moved_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
        $history[] = $entry;
        $fields['current_path'] = $toRelative;
        $fields['path_history'] = $history;
        $asset->setFields($fields);
        $this->assets->save($asset);

        $this->audit->record($asset->getUuid(), $fromRelative, $toRelative, 'state_transition', new \DateTimeImmutable());
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        return !str_starts_with($path, '/') && !str_contains($path, '..'.DIRECTORY_SEPARATOR) && !str_contains($path, '../');
    }
}
