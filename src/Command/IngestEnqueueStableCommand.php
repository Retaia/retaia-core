<?php

namespace App\Command;

use App\Ingest\Service\IngestStableFileEnqueueService;
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
        private IngestStableFileEnqueueService $ingestStableFiles,
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
        $result = $this->ingestStableFiles->enqueueStableFiles($limit);

        $io->success(sprintf(
            'Queued %d stable file(s). Missing: %d. Unmatched sidecars: %d.',
            (int) ($result['queued'] ?? 0),
            (int) ($result['missing'] ?? 0),
            (int) ($result['unmatched_sidecars'] ?? 0)
        ));

        return Command::SUCCESS;
    }
}
