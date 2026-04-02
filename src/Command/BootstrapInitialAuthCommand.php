<?php

declare(strict_types=1);

namespace App\Command;

use App\Auth\AuthClientRegistryEntry;
use App\Auth\AuthClientRegistryRepositoryInterface;
use App\Domain\AuthClient\ClientKind;
use App\Entity\User;
use App\User\Repository\UserRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:bootstrap:initial-auth',
    description: 'Create the initial admin user and default technical clients without storing secrets in migrations',
)]
final class BootstrapInitialAuthCommand extends Command
{
    public function __construct(
        private UserRepositoryInterface $users,
        private UserPasswordHasherInterface $passwordHasher,
        private AuthClientRegistryRepositoryInterface $clientRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Admin email to bootstrap', 'admin@retaia.local')
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Admin password to use instead of a generated one')
            ->addOption('reset-admin-password', null, InputOption::VALUE_NONE, 'Reset the password when the admin user already exists')
            ->addOption('agent-client-id', null, InputOption::VALUE_REQUIRED, 'Default agent client identifier', 'agent-default')
            ->addOption('agent-secret', null, InputOption::VALUE_REQUIRED, 'Agent client secret to use instead of a generated one')
            ->addOption('mcp-client-id', null, InputOption::VALUE_REQUIRED, 'Default MCP client identifier', 'mcp-default')
            ->addOption('mcp-secret', null, InputOption::VALUE_REQUIRED, 'MCP client secret to use instead of a generated one')
            ->addOption('rotate-existing-secrets', null, InputOption::VALUE_NONE, 'Rotate client secrets even when the registry entries already exist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $adminEmail = $this->requiredTrimmedOption($input, 'admin-email');
        $agentClientId = $this->requiredTrimmedOption($input, 'agent-client-id');
        $mcpClientId = $this->requiredTrimmedOption($input, 'mcp-client-id');

        if ($adminEmail === '' || $agentClientId === '' || $mcpClientId === '') {
            $io->error('admin-email, agent-client-id and mcp-client-id must be non-empty.');

            return Command::FAILURE;
        }

        $adminPassword = $this->bootstrapAdmin(
            $adminEmail,
            $this->optionalTrimmedOption($input, 'admin-password'),
            (bool) $input->getOption('reset-admin-password'),
            $io,
        );
        $agentSecret = $this->bootstrapClient(
            $agentClientId,
            ClientKind::AGENT,
            $this->optionalTrimmedOption($input, 'agent-secret'),
            (bool) $input->getOption('rotate-existing-secrets'),
            $io,
        );
        $mcpSecret = $this->bootstrapClient(
            $mcpClientId,
            ClientKind::MCP,
            $this->optionalTrimmedOption($input, 'mcp-secret'),
            (bool) $input->getOption('rotate-existing-secrets'),
            $io,
        );

        $rows = [
            ['admin_email', $adminEmail],
            ['admin_password', $adminPassword ?? '(unchanged)'],
            ['agent_client_id', $agentClientId],
            ['agent_secret', $agentSecret ?? '(unchanged)'],
            ['mcp_client_id', $mcpClientId],
            ['mcp_secret', $mcpSecret ?? '(unchanged)'],
        ];

        $io->success('Initial auth bootstrap complete.');
        $io->table(['field', 'value'], $rows);

        return Command::SUCCESS;
    }

    private function bootstrapAdmin(
        string $email,
        ?string $providedPassword,
        bool $resetPassword,
        SymfonyStyle $io,
    ): ?string {
        $existing = $this->users->findByEmail($email);
        if ($existing instanceof User) {
            if (!in_array('ROLE_ADMIN', $existing->getRoles(), true)) {
                throw new \RuntimeException(sprintf('Existing user "%s" is not an admin.', $email));
            }

            if ($providedPassword === null && !$resetPassword) {
                $io->note(sprintf('Admin user "%s" already exists; password left unchanged.', $email));

                return null;
            }

            $password = $providedPassword ?? self::generatedPassword();
            $this->users->save(
                $existing
                    ->withPasswordHash($this->passwordHasher->hashPassword($existing, $password))
                    ->withEmailVerified(true)
            );
            $io->note(sprintf('Admin user "%s" password updated.', $email));

            return $password;
        }

        $password = $providedPassword ?? self::generatedPassword();
        $user = new User(
            self::generatedUserId($email),
            $email,
            '',
            ['ROLE_ADMIN'],
            true,
        );
        $this->users->save($user->withPasswordHash($this->passwordHasher->hashPassword($user, $password)));
        $io->note(sprintf('Admin user "%s" created.', $email));

        return $password;
    }

    private function bootstrapClient(
        string $clientId,
        string $clientKind,
        ?string $providedSecret,
        bool $rotateExisting,
        SymfonyStyle $io,
    ): ?string {
        $existing = $this->clientRegistry->findByClientId($clientId);
        if ($existing instanceof AuthClientRegistryEntry) {
            if ($existing->clientKind !== $clientKind) {
                throw new \RuntimeException(sprintf(
                    'Existing client "%s" has kind "%s", expected "%s".',
                    $clientId,
                    $existing->clientKind,
                    $clientKind,
                ));
            }

            if ($providedSecret === null && !$rotateExisting && $existing->secretKey !== null && $existing->secretKey !== '') {
                $io->note(sprintf('Client "%s" already exists; secret left unchanged.', $clientId));

                return null;
            }

            $secret = $providedSecret ?? self::generatedSecret();
            $this->clientRegistry->save(new AuthClientRegistryEntry(
                $existing->clientId,
                $existing->clientKind,
                $secret,
                $existing->clientLabel,
                $existing->openPgpPublicKey,
                $existing->openPgpFingerprint,
                $existing->registeredAt,
                $existing->rotatedAt,
            ));
            $io->note(sprintf('Client "%s" secret stored.', $clientId));

            return $secret;
        }

        $secret = $providedSecret ?? self::generatedSecret();
        $this->clientRegistry->save(new AuthClientRegistryEntry(
            $clientId,
            $clientKind,
            $secret,
            null,
            null,
            null,
            null,
            null,
        ));
        $io->note(sprintf('Client "%s" created.', $clientId));

        return $secret;
    }

    private function requiredTrimmedOption(InputInterface $input, string $name): string
    {
        return trim((string) $input->getOption($name));
    }

    private function optionalTrimmedOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function generatedPassword(): string
    {
        return sprintf('Retaia-%s!', bin2hex(random_bytes(8)));
    }

    private static function generatedSecret(): string
    {
        return bin2hex(random_bytes(24));
    }

    private static function generatedUserId(string $email): string
    {
        return substr('bootstrap'.hash('sha256', strtolower($email)), 0, 32);
    }
}
