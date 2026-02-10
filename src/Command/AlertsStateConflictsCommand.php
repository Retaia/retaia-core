<?php

namespace App\Command;

use App\Observability\Repository\MetricEventRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:alerts:state-conflicts', description: 'Alert on excessive state conflicts and lock acquisition failures')]
final class AlertsStateConflictsCommand extends Command
{
    public function __construct(
        private MetricEventRepository $metrics,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('window-minutes', null, InputOption::VALUE_OPTIONAL, 'Sliding window size in minutes', '15');
        $this->addOption('state-conflicts-threshold', null, InputOption::VALUE_OPTIONAL, 'Maximum allowed STATE_CONFLICT count in window', '20');
        $this->addOption('lock-failed-threshold', null, InputOption::VALUE_OPTIONAL, 'Maximum allowed failed lock acquisitions per lock type in window', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $windowMinutes = max(1, (int) $input->getOption('window-minutes'));
        $stateThreshold = max(1, (int) $input->getOption('state-conflicts-threshold'));
        $lockThreshold = max(1, (int) $input->getOption('lock-failed-threshold'));

        $since = new \DateTimeImmutable(sprintf('-%d minutes', $windowMinutes));

        $stateConflicts = $this->metrics->countSince('api.error.STATE_CONFLICT', $since);
        $moveLockFails = $this->metrics->countSince('lock.acquire.failed.asset_move_lock', $since);
        $purgeLockFails = $this->metrics->countSince('lock.acquire.failed.asset_purge_lock', $since);

        $io->table(
            ['Metric', 'Count', 'Threshold'],
            [
                ['api.error.STATE_CONFLICT', (string) $stateConflicts, (string) $stateThreshold],
                ['lock.acquire.failed.asset_move_lock', (string) $moveLockFails, (string) $lockThreshold],
                ['lock.acquire.failed.asset_purge_lock', (string) $purgeLockFails, (string) $lockThreshold],
            ]
        );

        if ($stateConflicts > $stateThreshold || $moveLockFails > $lockThreshold || $purgeLockFails > $lockThreshold) {
            $io->error(sprintf('Alert threshold exceeded in the last %d minute(s).', $windowMinutes));

            return Command::FAILURE;
        }

        $io->success(sprintf('No alert. Metrics are within thresholds for the last %d minute(s).', $windowMinutes));

        return Command::SUCCESS;
    }
}

