<?php

namespace App\Command;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Ingest\Repository\PathAuditRepository;
use App\Ingest\Service\WatchPathResolver;
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
        private WatchPathResolver $watchPathResolver,
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

        if (!is_dir(dirname($targetAbsolute)) && !mkdir(dirname($targetAbsolute), 0777, true) && !is_dir(dirname($targetAbsolute))) {
            throw new \RuntimeException(sprintf('Unable to create target directory for %s', $targetRelative));
        }

        if (is_file($fromAbsolute)) {
            [$finalRelative, $finalAbsolute] = $this->resolveAvailableTarget($root, $targetFolder, $targetRelative, $asset->getUuid());
            if (!@rename($fromAbsolute, $finalAbsolute)) {
                throw new \RuntimeException(sprintf('Unable to move %s to %s', $fromRelative, $finalRelative));
            }
            $remap = $this->moveDerivedFilesForAsset($asset->getUuid(), $targetFolder, $root);
            $this->applyDerivedPathRemap($asset, $remap);
            $this->persistPathUpdate($asset, $fromRelative, $finalRelative);

            return 1;
        }

        if (is_file($targetAbsolute)) {
            $remap = $this->moveDerivedFilesForAsset($asset->getUuid(), $targetFolder, $root);
            $this->applyDerivedPathRemap($asset, $remap);
            $this->persistPathUpdate($asset, $fromRelative, $targetRelative);

            return 0;
        }

        return 0;
    }

    private function persistPathUpdate(Asset $asset, string $fromRelative, string $toRelative): void
    {
        $fields = $asset->getFields();
        if (($fields['current_path'] ?? null) === $toRelative) {
            return;
        }

        $history = $fields['path_history'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }
        $last = $history[count($history) - 1] ?? null;
        if (is_array($last) && ($last['from'] ?? null) === $fromRelative && ($last['to'] ?? null) === $toRelative) {
            $fields['current_path'] = $toRelative;
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
        $fields['current_path'] = $toRelative;
        $fields['path_history'] = $history;
        $asset->setFields($fields);
        $this->assets->save($asset);

        $this->audit->record($asset->getUuid(), $fromRelative, $toRelative, 'state_transition', new \DateTimeImmutable());
    }

    /**
     * @return array<string, string> oldPath => newPath
     */
    private function moveDerivedFilesForAsset(string $assetUuid, string $targetFolder, string $root): array
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
            $sourceAbsolute = $root.DIRECTORY_SEPARATOR.$storagePath;
            $targetAbsolute = $root.DIRECTORY_SEPARATOR.$targetStoragePath;

            if (!is_dir(dirname($targetAbsolute)) && !mkdir(dirname($targetAbsolute), 0777, true) && !is_dir(dirname($targetAbsolute))) {
                throw new \RuntimeException(sprintf('Unable to create derived target directory for %s', $targetStoragePath));
            }

            if (is_file($sourceAbsolute)) {
                if (!@rename($sourceAbsolute, $targetAbsolute)) {
                    throw new \RuntimeException(sprintf('Unable to move derived file %s to %s', $storagePath, $targetStoragePath));
                }
            } elseif (!is_file($targetAbsolute)) {
                continue;
            }

            $this->connection->update('asset_derived_file', ['storage_path' => $targetStoragePath], ['id' => $id]);
            $remap[$storagePath] = $targetStoragePath;
        }

        return $remap;
    }

    /**
     * @param array<string, string> $remap
     */
    private function applyDerivedPathRemap(Asset $asset, array $remap): void
    {
        if ($remap === []) {
            return;
        }

        $fields = $asset->getFields();

        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $sidecars = is_array($paths['sidecars_relative'] ?? null) ? $paths['sidecars_relative'] : [];
        $mappedSidecars = [];
        foreach ($sidecars as $sidecar) {
            $normalized = ltrim((string) $sidecar, '/');
            $mappedSidecars[] = $remap[$normalized] ?? $normalized;
        }
        $paths['sidecars_relative'] = array_values(array_unique(array_filter(array_map('strval', $mappedSidecars), static fn (string $v): bool => $v !== '')));

        $derived = is_array($fields['derived'] ?? null) ? $fields['derived'] : [];
        $manifest = is_array($derived['derived_manifest'] ?? null) ? $derived['derived_manifest'] : [];
        foreach ($manifest as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $ref = ltrim((string) ($item['ref'] ?? ''), '/');
            if ($ref === '') {
                continue;
            }
            if (isset($remap[$ref])) {
                $item['ref'] = $remap[$ref];
                $manifest[$index] = $item;
            }
        }
        $derived['derived_manifest'] = $manifest;

        $fields['paths'] = $paths;
        $fields['derived'] = $derived;
        $asset->setFields($fields);
        $this->assets->save($asset);
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        return !str_starts_with($path, '/') && !str_contains($path, '..'.DIRECTORY_SEPARATOR) && !str_contains($path, '../');
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveAvailableTarget(string $root, string $targetFolder, string $defaultRelative, string $assetUuid): array
    {
        $baseAbsolute = $root.DIRECTORY_SEPARATOR.$defaultRelative;
        if (!is_file($baseAbsolute)) {
            return [$defaultRelative, $baseAbsolute];
        }

        $ext = pathinfo($baseAbsolute, PATHINFO_EXTENSION);
        $name = pathinfo($baseAbsolute, PATHINFO_FILENAME);
        $suffix = substr(str_replace('-', '', $assetUuid), 0, 6);
        $attempt = 0;

        while (true) {
            $candidateName = $attempt === 0
                ? ($ext === '' ? sprintf('%s__%s', $name, $suffix) : sprintf('%s__%s.%s', $name, $suffix, $ext))
                : ($ext === '' ? sprintf('%s__%s_%d', $name, $suffix, $attempt) : sprintf('%s__%s_%d.%s', $name, $suffix, $attempt, $ext));

            $relative = $targetFolder.DIRECTORY_SEPARATOR.$candidateName;
            $absolute = $root.DIRECTORY_SEPARATOR.$relative;
            if (!is_file($absolute)) {
                return [$relative, $absolute];
            }

            ++$attempt;
        }
    }
}
