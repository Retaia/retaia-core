<?php

namespace App\Command;

use App\User\Repository\PasswordResetTokenRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:password-reset:cleanup', description: 'Purge expired password reset tokens')]
final class PasswordResetCleanupCommand extends Command
{
    public function __construct(
        private PasswordResetTokenRepositoryInterface $tokens,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = $this->tokens->purgeExpired(new \DateTimeImmutable());

        $io->success(sprintf('Purged %d expired password reset token(s).', $count));

        return Command::SUCCESS;
    }
}
