<?php

namespace App\Command;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Ingest\Repository\PathAuditRepository;
use App\Storage\BusinessStorageRegistryInterface;
use Doctrine\DBAL\Connection;
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
        private BusinessStorageRegistryInterface $storageRegistry,
        private AssetRepositoryInterface $assets,
        private PathAuditRepository $audit,
        private Connection $connection,
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
        $failed = 0;

        $assets = array_merge(
            $this->assets->listAssets(AssetState::ARCHIVED->value, null, null, $limit),
            $this->assets->listAssets(AssetState::REJECTED->value, null, null, $limit),
        );

        foreach ($assets as $asset) {
            try {
                $processed += $this->processAsset($asset);
            } catch (\Throwable $e) {
                ++$failed;
                $io->warning(sprintf('Skipping asset %s: %s', $asset->getUuid(), $e->getMessage()));
            }
        }

        if ($failed > 0) {
            $io->warning(sprintf('Encountered %d move failure(s).', $failed));
        }
        $io->success(sprintf('Moved %d file(s) to ARCHIVE/REJECTS.', $processed));

        return Command::SUCCESS;
    }

    private function processAsset(Asset $asset): int
    {
        $fields = $asset->getFields();
        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $sourcePath = (string) ($paths['original_relative'] ?? '');
        if ($sourcePath === '') {
            return 0;
        }
        if (!$this->isSafeRelativePath($sourcePath)) {
            return 0;
        }

        $fromRelative = ltrim($sourcePath, '/');
        $targetFolder = $asset->getState() === AssetState::ARCHIVED ? 'ARCHIVE' : 'REJECTS';
        $targetRelative = $targetFolder.'/'.basename($fromRelative);
        $storage = $this->storageForAsset($asset);

        if ($storage->fileExists($fromRelative)) {
            [$finalRelative] = $this->resolveAvailableTarget($storage, $targetFolder, $targetRelative, $asset->getUuid());
            $storage->move($fromRelative, $finalRelative);
            $this->moveDerivedFilesForAsset($storage, $asset->getUuid(), $targetFolder);
            $this->persistPathUpdate($asset, $fromRelative, $finalRelative);

            return 1;
        }

        if ($storage->fileExists($targetRelative)) {
            $this->moveDerivedFilesForAsset($storage, $asset->getUuid(), $targetFolder);
            $this->persistPathUpdate($asset, $fromRelative, $targetRelative);

            return 0;
        }

        return 0;
    }

    private function persistPathUpdate(Asset $asset, string $fromRelative, string $toRelative): void
    {
        $fields = $asset->getFields();
        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        if (($paths['original_relative'] ?? null) === $toRelative) {
            return;
        }

        $history = $fields['path_history'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }
        $last = $history[count($history) - 1] ?? null;
        if (is_array($last) && ($last['from'] ?? null) === $fromRelative && ($last['to'] ?? null) === $toRelative) {
            $paths['original_relative'] = $toRelative;
            $fields['paths'] = $paths;
            $asset->setFields($fields);
            $this->assets->save($asset);

            return;
        }

        $entry = [
            'from' => $fromRelative,
            'to' => $toRelative,
            'reason' => 'state_transition',
            'moved_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
        $history[] = $entry;
        $paths['original_relative'] = $toRelative;
        $fields['paths'] = $paths;
        $fields['path_history'] = $history;
        $asset->setFields($fields);
        $this->assets->save($asset);

        $this->audit->record($asset->getUuid(), $fromRelative, $toRelative, 'state_transition', new \DateTimeImmutable());
    }

    /**
     * @return array<string, string> oldPath => newPath
     */
    private function moveDerivedFilesForAsset(\App\Storage\BusinessStorageInterface $storage, string $assetUuid, string $targetFolder): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, storage_path FROM asset_derived_file WHERE asset_uuid = :assetUuid',
                ['assetUuid' => $assetUuid]
            );
        } catch (\Throwable) {
            return [];
        }

        $remap = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            $storagePathRaw = (string) ($row['storage_path'] ?? '');
            $storagePath = ltrim(trim($storagePathRaw), '/');
            if ($id === '' || !$this->isSafeRelativePath($storagePath)) {
                continue;
            }

            $targetStoragePath = $targetFolder.'/'.$storagePath;
            if ($storage->fileExists($storagePath)) {
                $storage->move($storagePath, $targetStoragePath);
            } elseif (!$storage->fileExists($targetStoragePath)) {
                continue;
            }

            $this->connection->update('asset_derived_file', ['storage_path' => $targetStoragePath], ['id' => $id]);
            $remap[$storagePath] = $targetStoragePath;
        }

        return $remap;
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        return !str_starts_with($path, '/') && !str_contains($path, '..\\') && !str_contains($path, '../');
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveAvailableTarget(\App\Storage\BusinessStorageInterface $storage, string $targetFolder, string $defaultRelative, string $assetUuid): array
    {
        if (!$storage->fileExists($defaultRelative)) {
            return [$defaultRelative, $defaultRelative];
        }

        $ext = pathinfo($defaultRelative, PATHINFO_EXTENSION);
        $name = pathinfo($defaultRelative, PATHINFO_FILENAME);
        $suffix = substr(str_replace('-', '', $assetUuid), 0, 6);
        $attempt = 0;

        while (true) {
            $candidateName = $attempt === 0
                ? ($ext === '' ? sprintf('%s__%s', $name, $suffix) : sprintf('%s__%s.%s', $name, $suffix, $ext))
                : ($ext === '' ? sprintf('%s__%s_%d', $name, $suffix, $attempt) : sprintf('%s__%s_%d.%s', $name, $suffix, $attempt, $ext));

            $relative = $targetFolder.'/'.$candidateName;
            if (!$storage->fileExists($relative)) {
                return [$relative, $relative];
            }

            ++$attempt;
        }
    }

    private function storageForAsset(Asset $asset): \App\Storage\BusinessStorageInterface
    {
        $fields = $asset->getFields();
        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $storageId = trim((string) ($paths['storage_id'] ?? ''));
        if ($storageId === '') {
            throw new \RuntimeException(sprintf('Asset %s is missing canonical paths.storage_id.', $asset->getUuid()));
        }

        return $this->storageRegistry->get($storageId)->storage;
    }
}
