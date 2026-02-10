<?php

namespace App\Command;

use App\Ingest\Port\FilePollerInterface;
use App\Ingest\Port\ScanStateStoreInterface;
use App\Ingest\Service\WatchPathResolver;
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
        private WatchPathResolver $watchPathResolver,
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
        $watchPath = $this->watchPathResolver->resolve();
        $scannedAt = new \DateTimeImmutable();
        $files = array_map(function (array $item) use ($scannedAt): array {
            $state = $this->scanStateStore->recordDetectedFile(
                (string) $item['path'],
                (int) $item['size'],
                $item['mtime'],
                $scannedAt
            );

            return [
                'path' => $state['path'],
                'size' => $state['size'],
                'mtime' => $state['mtime'],
                'stable_count' => $state['stable_count'],
                'status' => $state['status'],
            ];
        }, $this->poller->poll($limit));

        if ((bool) $input->getOption('json')) {
            $payload = [
                'watch_path' => $watchPath,
                'count' => count($files),
                'items' => array_map(static fn (array $item): array => [
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
        $io->writeln(sprintf('Watch path: %s', $watchPath));
        $io->writeln(sprintf('Detected files: %d', count($files)));
        if ($files === []) {
            $io->note('No files detected.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Path', 'Size (bytes)', 'MTime', 'Stable Count', 'Status'],
            array_map(static fn (array $item): array => [
                $item['path'],
                (string) $item['size'],
                $item['mtime']->format('Y-m-d H:i:s'),
                (string) $item['stable_count'],
                $item['status'],
            ], $files)
        );

        return Command::SUCCESS;
    }
}
