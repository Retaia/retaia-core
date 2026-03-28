<?php

namespace App\Command;

use App\Ingest\Port\FilePollerInterface;
use App\Ingest\Port\ScanStateStoreInterface;
use App\Storage\BusinessStorageRegistryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:ingest:poll', description: 'Poll watched folder and list detected files')]
final class IngestPollCommand extends Command
{
    public function __construct(
        private BusinessStorageRegistryInterface $storageRegistry,
        private FilePollerInterface $poller,
        private ScanStateStoreInterface $scanStateStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum number of files to list', '100');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Render output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $scannedAt = new \DateTimeImmutable();
        $files = array_map(function (array $item) use ($scannedAt): array {
            $storageId = (string) ($item['storage_id'] ?? '');
            $storage = $this->storageRegistry->get($storageId)->storage;
            $relativePath = ltrim((string) $item['path'], '/');
            $scanPath = sprintf('%s/%s', $storage->watchDirectory(), $relativePath);
            $state = $this->scanStateStore->recordDetectedFile(
                $storageId,
                $scanPath,
                (int) $item['size'],
                $item['mtime'],
                $scannedAt
            );

            return [
                'storage_id' => $state['storage_id'],
                'path' => $state['path'],
                'size' => $state['size'],
                'mtime' => $state['mtime'],
                'stable_count' => $state['stable_count'],
                'status' => $state['status'],
            ];
        }, $this->poller->poll($limit));

        if ((bool) $input->getOption('json')) {
            $payload = [
                'storages' => array_map(
                    static fn (\App\Storage\BusinessStorageDefinition $definition): array => [
                        'storage_id' => $definition->id,
                        'watch_path' => $definition->storage->absoluteWatchPath(),
                    ],
                    $this->storageRegistry->ingestEnabled()
                ),
                'count' => count($files),
                'items' => array_map(static fn (array $item): array => [
                    'storage_id' => $item['storage_id'],
                    'path' => $item['path'],
                    'size' => $item['size'],
                    'mtime' => $item['mtime']->format(DATE_ATOM),
                    'stable_count' => $item['stable_count'],
                    'status' => $item['status'],
                ], $files),
            ];
            $output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title('Ingest Poll');
        foreach ($this->storageRegistry->ingestEnabled() as $definition) {
            $io->writeln(sprintf('Storage %s watch path: %s', $definition->id, $definition->storage->absoluteWatchPath()));
        }
        $io->writeln(sprintf('Detected files: %d', count($files)));
        if ($files === []) {
            $io->note('No files detected.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Storage / Path', 'Size (bytes)', 'MTime', 'Stable Count', 'Status'],
            array_map(static fn (array $item): array => [
                $item['storage_id'].':'.$item['path'],
                (string) $item['size'],
                $item['mtime']->format('Y-m-d H:i:s'),
                (string) $item['stable_count'],
                $item['status'],
            ], $files)
        );

        return Command::SUCCESS;
    }
}
