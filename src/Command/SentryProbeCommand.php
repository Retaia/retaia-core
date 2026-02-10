<?php

namespace App\Command;

use Sentry\Severity;
use Sentry\State\HubInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:sentry:probe', description: 'Send a probe event to Sentry (production check)')]
final class SentryProbeCommand extends Command
{
    public function __construct(
        private HubInterface $sentry,
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire('%env(SENTRY_DSN)%')]
        private string $sentryDsn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('allow-non-prod', null, InputOption::VALUE_NONE, 'Allow sending probe outside prod');
        $this->addOption('message', null, InputOption::VALUE_OPTIONAL, 'Probe message', 'sentry probe');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $allowNonProd = (bool) $input->getOption('allow-non-prod');
        $message = trim((string) $input->getOption('message'));
        if ($message === '') {
            $message = 'sentry probe';
        }

        if ($this->environment !== 'prod' && !$allowNonProd) {
            $io->warning(sprintf('Skipped: current env is "%s". Use --allow-non-prod to override.', $this->environment));

            return Command::SUCCESS;
        }

        if (!$this->isValidProdDsn($this->sentryDsn)) {
            $io->error('SENTRY_DSN is missing or invalid (expected host: sentry.fullfrontend.be).');

            return Command::FAILURE;
        }

        $eventId = $this->sentry->captureMessage(
            sprintf('%s [%s]', $message, (new \DateTimeImmutable())->format(DATE_ATOM)),
            Severity::info()
        );

        $client = $this->sentry->getClient();
        if ($client !== null) {
            $client->flush();
        }

        if ($eventId === null) {
            $io->error('Sentry probe failed (no event id returned).');

            return Command::FAILURE;
        }

        $io->success(sprintf('Sentry probe sent. Event ID: %s', (string) $eventId));

        return Command::SUCCESS;
    }

    private function isValidProdDsn(string $dsn): bool
    {
        $dsn = trim($dsn);
        if ($dsn === '') {
            return false;
        }

        $parts = parse_url($dsn);
        if (!is_array($parts)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        return $host === 'sentry.fullfrontend.be';
    }
}

