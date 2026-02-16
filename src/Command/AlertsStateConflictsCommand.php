<?php

namespace App\Command;

use App\Lock\Repository\OperationLockRepository;
use App\Observability\MetricName;
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
        private OperationLockRepository $locks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('window-minutes', null, InputOption::VALUE_OPTIONAL, 'Sliding window size in minutes', '15');
        $this->addOption('state-conflicts-threshold', null, InputOption::VALUE_OPTIONAL, 'Maximum allowed STATE_CONFLICT count in window', '20');
        $this->addOption('lock-failed-threshold', null, InputOption::VALUE_OPTIONAL, 'Maximum allowed failed lock acquisitions per lock type in window', '10');
        $this->addOption('active-locks-threshold', null, InputOption::VALUE_OPTIONAL, 'Maximum allowed active operation locks', '200');
        $this->addOption('stale-locks-threshold', null, InputOption::VALUE_OPTIONAL, 'Maximum allowed stale active operation locks', '0');
        $this->addOption('stale-lock-minutes', null, InputOption::VALUE_OPTIONAL, 'Age threshold in minutes to classify an active lock as stale', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $windowMinutes = max(1, (int) $input->getOption('window-minutes'));
        $stateThreshold = max(1, (int) $input->getOption('state-conflicts-threshold'));
        $lockThreshold = max(1, (int) $input->getOption('lock-failed-threshold'));
        $activeLocksThreshold = max(0, (int) $input->getOption('active-locks-threshold'));
        $staleLocksThreshold = max(0, (int) $input->getOption('stale-locks-threshold'));
        $staleLockMinutes = max(1, (int) $input->getOption('stale-lock-minutes'));

        $since = new \DateTimeImmutable(sprintf('-%d minutes', $windowMinutes));
        $staleBefore = new \DateTimeImmutable(sprintf('-%d minutes', $staleLockMinutes));

        $stateConflictMetric = MetricName::apiError('STATE_CONFLICT');
        $moveLockMetric = MetricName::lockAcquireFailed('asset_move_lock');
        $purgeLockMetric = MetricName::lockAcquireFailed('asset_purge_lock');

        $stateConflicts = $this->metrics->countSince($stateConflictMetric, $since);
        $moveLockFails = $this->metrics->countSince($moveLockMetric, $since);
        $purgeLockFails = $this->metrics->countSince($purgeLockMetric, $since);
        $activeLocks = $this->locks->countActiveLocks();
        $staleLocks = $this->locks->countStaleActiveLocks($staleBefore);

        $io->table(
            ['Metric', 'Count', 'Threshold'],
            [
                [$stateConflictMetric, (string) $stateConflicts, (string) $stateThreshold],
                [$moveLockMetric, (string) $moveLockFails, (string) $lockThreshold],
                [$purgeLockMetric, (string) $purgeLockFails, (string) $lockThreshold],
                ['asset_operation_lock.active', (string) $activeLocks, (string) $activeLocksThreshold],
                [sprintf('asset_operation_lock.stale(>%dm)', $staleLockMinutes), (string) $staleLocks, (string) $staleLocksThreshold],
            ]
        );

        if (
            $stateConflicts > $stateThreshold
            || $moveLockFails > $lockThreshold
            || $purgeLockFails > $lockThreshold
            || $activeLocks > $activeLocksThreshold
            || $staleLocks > $staleLocksThreshold
        ) {
            $io->error(sprintf('Alert threshold exceeded in the last %d minute(s).', $windowMinutes));

            return Command::FAILURE;
        }

        $io->success(sprintf('No alert. Metrics are within thresholds for the last %d minute(s).', $windowMinutes));

        return Command::SUCCESS;
    }
}
