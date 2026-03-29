<?php

namespace App\Command;

use App\Ingest\Service\IngestOutboxMoveService;
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
        private IngestOutboxMoveService $outboxMoves,
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
        $result = $this->outboxMoves->apply($limit);

        if (($result['failed'] ?? 0) > 0) {
            $io->warning(sprintf('Encountered %d move failure(s).', (int) $result['failed']));
        }
        $io->success(sprintf('Moved %d file(s) to ARCHIVE/REJECTS.', (int) ($result['processed'] ?? 0)));

        return Command::SUCCESS;
    }
}
