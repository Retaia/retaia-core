<?php

namespace App\Command;

use App\Lock\OperationLockType;
use App\Lock\Repository\OperationLockRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:locks:watchdog-recover', description: 'Release stale operation locks for move/purge flows')]
final class LocksWatchdogRecoverCommand extends Command
{
    public function __construct(
        private OperationLockRepository $locks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('stale-lock-minutes', null, InputOption::VALUE_OPTIONAL, 'Age threshold in minutes to classify an active lock as stale', '30');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report stale locks without releasing them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $staleLockMinutes = max(1, (int) $input->getOption('stale-lock-minutes'));
        $dryRun = (bool) $input->getOption('dry-run');
        $before = new \DateTimeImmutable(sprintf('-%d minutes', $staleLockMinutes));

        $rows = [];
        $releasedTotal = 0;
        foreach ([OperationLockType::MOVE, OperationLockType::PURGE] as $type) {
            $stale = $this->locks->countStaleActiveLocksByType($type, $before);
            $released = 0;
            if (!$dryRun && $stale > 0) {
                $released = $this->locks->releaseStaleActiveLocksByType($type, $before);
            }

            $releasedTotal += $released;
            $rows[] = [$type->value, (string) $stale, (string) $released];
        }

        $io->table(['Lock type', 'Stale active', 'Released'], $rows);

        if ($dryRun) {
            $io->success(sprintf('Dry-run complete for locks older than %d minute(s).', $staleLockMinutes));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Released %d stale lock(s) older than %d minute(s).', $releasedTotal, $staleLockMinutes));

        return Command::SUCCESS;
    }
}

