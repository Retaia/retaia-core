<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:ingest:cron-tick', description: 'Run one ingest cron cycle: poll, enqueue stable files, apply outbox')]
final class IngestCronTickCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('poll-limit', null, InputOption::VALUE_OPTIONAL, 'Maximum files for poll step', '100');
        $this->addOption('enqueue-limit', null, InputOption::VALUE_OPTIONAL, 'Maximum files for enqueue step', '100');
        $this->addOption('apply-limit', null, InputOption::VALUE_OPTIONAL, 'Maximum assets for apply-outbox step', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $application = $this->getApplication();
        if ($application === null) {
            $io->error('Console application is not available.');

            return Command::FAILURE;
        }

        $pollLimit = max(1, (int) $input->getOption('poll-limit'));
        $enqueueLimit = max(1, (int) $input->getOption('enqueue-limit'));
        $applyLimit = max(1, (int) $input->getOption('apply-limit'));

        $steps = [
            ['name' => 'app:ingest:poll', 'args' => ['--limit' => $pollLimit]],
            ['name' => 'app:ingest:enqueue-stable', 'args' => ['--limit' => $enqueueLimit]],
            ['name' => 'app:ingest:apply-outbox', 'args' => ['--limit' => $applyLimit]],
        ];

        foreach ($steps as $step) {
            $command = $application->find((string) $step['name']);
            $commandInput = new ArrayInput((array) $step['args']);
            $stepOutput = $output->isVerbose()
                ? $output
                : new BufferedOutput(OutputInterface::VERBOSITY_QUIET, $output->isDecorated());
            $exitCode = $command->run($commandInput, $stepOutput);

            if ($exitCode !== Command::SUCCESS) {
                $io->error(sprintf('Step "%s" failed with exit code %d.', $step['name'], $exitCode));

                return Command::FAILURE;
            }
        }

        $io->success('Ingest cron tick completed.');

        return Command::SUCCESS;
    }
}
