<?php

namespace App\Command;

use App\Storage\BusinessStorageRegistryInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:ops:readiness-check', description: 'Run basic V1 operational readiness checks')]
final class OpsReadinessCheckCommand extends Command
{
    public function __construct(
        private Connection $connection,
        private BusinessStorageRegistryInterface $storageRegistry,
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire('%env(default::SENTRY_DSN)%')]
        private ?string $sentryDsn,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $failures = [];

        if (!$this->checkDatabase()) {
            $failures[] = 'Database connectivity check failed.';
        }

        try {
            foreach ($this->storageRegistry->all() as $definition) {
                foreach ($definition->storage->managedDirectories() as $folder) {
                    if (!$definition->storage->directoryExists($folder)) {
                        $failures[] = sprintf('Missing ingest directory: %s:%s', $definition->id, $folder);
                        continue;
                    }

                    if (!$definition->storage->probeWritableDirectory($folder)) {
                        $failures[] = sprintf('Ingest directory is not writable: %s:%s', $definition->id, $folder);
                    }
                }
            }
        } catch (\Throwable $e) {
            $failures[] = sprintf('Ingest path resolution failed: %s', $e->getMessage());
        }

        if ($this->environment === 'prod' && !$this->isValidSentryDsn((string) $this->sentryDsn)) {
            $failures[] = 'SENTRY_DSN is missing or invalid for production (expected host: sentry.fullfrontend.be).';
        }

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $io->error($failure);
            }

            return Command::FAILURE;
        }

        $io->success('Ops readiness checks passed.');

        return Command::SUCCESS;
    }

    private function checkDatabase(): bool
    {
        try {
            $value = $this->connection->fetchOne('SELECT 1');

            return (string) $value === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    private function isValidSentryDsn(string $dsn): bool
    {
        $dsn = trim($dsn);
        if ($dsn === '') {
            return false;
        }

        $parts = parse_url($dsn);
        if (!is_array($parts)) {
            return false;
        }

        return strtolower((string) ($parts['host'] ?? '')) === 'sentry.fullfrontend.be';
    }
}
