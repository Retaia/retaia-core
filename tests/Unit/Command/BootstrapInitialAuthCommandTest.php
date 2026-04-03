<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Auth\AuthClientRegistryEntry;
use App\Auth\AuthClientRegistryRepositoryInterface;
use App\Command\BootstrapInitialAuthCommand;
use App\Entity\User;
use App\User\Repository\UserRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<string, User> */
    public array $usersByEmail = [];

    public function __construct(?User $initialUser = null)
    {
        if ($initialUser instanceof User) {
            $this->save($initialUser);
        }
    }

    public function findByEmail(string $email): ?User
    {
        return $this->usersByEmail[strtolower($email)] ?? null;
    }

    public function findById(string $id): ?User
    {
        foreach ($this->usersByEmail as $user) {
            if ($user->getId() === $id) {
                return $user;
            }
        }

        return null;
    }

    public function save(User $user): void
    {
        $this->usersByEmail[strtolower($user->getEmail())] = $user;
    }
}

final class BootstrapInitialAuthCommandTest extends TestCase
{
    public function testExecuteCreatesAdminAndDefaultClientsWhenMissing(): void
    {
        $users = new InMemoryUserRepository();
        $clients = new class() implements AuthClientRegistryRepositoryInterface {
            /** @var array<string, AuthClientRegistryEntry> */
            public array $entries = [];

            public function findByClientId(string $clientId): ?AuthClientRegistryEntry
            {
                return $this->entries[$clientId] ?? null;
            }

            public function findAll(): array
            {
                return array_values($this->entries);
            }

            public function save(AuthClientRegistryEntry $entry): void
            {
                $this->entries[$entry->clientId] = $entry;
            }
        };
        $passwordHasher = new class() implements UserPasswordHasherInterface {
            public function hashPassword(PasswordAuthenticatedUserInterface $user, string $plainPassword): string
            {
                return 'hashed:'.$plainPassword;
            }

            public function isPasswordValid(PasswordAuthenticatedUserInterface $user, string $plainPassword): bool
            {
                return false;
            }

            public function needsRehash(PasswordAuthenticatedUserInterface $user): bool
            {
                return false;
            }
        };

        $command = new BootstrapInitialAuthCommand($users, $passwordHasher, $clients);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--admin-password' => 'Admin-pass-123!',
            '--agent-secret' => 'agent-secret-test',
            '--mcp-secret' => 'mcp-secret-test',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Initial auth bootstrap complete.', $tester->getDisplay());

        $admin = $users->findByEmail('admin@retaia.local');
        self::assertInstanceOf(User::class, $admin);
        self::assertContains('ROLE_ADMIN', $admin->getRoles());
        self::assertTrue($admin->isEmailVerified());
        self::assertSame('hashed:Admin-pass-123!', $admin->getPassword());

        self::assertSame('agent-secret-test', $clients->findByClientId('agent-default')?->secretKey);
        self::assertSame('AGENT', $clients->findByClientId('agent-default')?->clientKind);
        self::assertSame('mcp-secret-test', $clients->findByClientId('mcp-default')?->secretKey);
        self::assertSame('MCP', $clients->findByClientId('mcp-default')?->clientKind);
    }

    public function testExecuteLeavesExistingSecretsUnchangedWithoutFlags(): void
    {
        $existingAdmin = new User('admin-1', 'admin@retaia.local', 'existing-hash', ['ROLE_ADMIN'], true);
        $users = new InMemoryUserRepository($existingAdmin);
        $agent = new AuthClientRegistryEntry('agent-default', 'AGENT', 'persisted-agent-secret', null, null, null, null, null);
        $mcp = new AuthClientRegistryEntry('mcp-default', 'MCP', 'persisted-mcp-secret', null, null, null, null, null);
        $clients = new class($agent, $mcp) implements AuthClientRegistryRepositoryInterface {
            /** @var array<string, AuthClientRegistryEntry> */
            private array $entries;

            public function __construct(AuthClientRegistryEntry ...$entries)
            {
                foreach ($entries as $entry) {
                    $this->entries[$entry->clientId] = $entry;
                }
            }

            public function findByClientId(string $clientId): ?AuthClientRegistryEntry
            {
                return $this->entries[$clientId] ?? null;
            }

            public function findAll(): array
            {
                return array_values($this->entries);
            }

            public function save(AuthClientRegistryEntry $entry): void
            {
                $this->entries[$entry->clientId] = $entry;
            }
        };
        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->expects(self::never())->method('hashPassword');

        $command = new BootstrapInitialAuthCommand($users, $passwordHasher, $clients);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('(unchanged)', $tester->getDisplay());
        self::assertSame('persisted-agent-secret', $clients->findByClientId('agent-default')?->secretKey);
        self::assertSame('persisted-mcp-secret', $clients->findByClientId('mcp-default')?->secretKey);
        $adminUser = $users->findByEmail('admin@retaia.local');
        self::assertSame('existing-hash', $adminUser?->getPassword());
        self::assertContains('ROLE_ADMIN', $adminUser?->getRoles() ?? []);
        self::assertTrue($adminUser?->isEmailVerified() ?? false);
    }
}
