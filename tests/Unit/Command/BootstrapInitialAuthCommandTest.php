<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Auth\AuthClientRegistryEntry;
use App\Command\BootstrapInitialAuthCommand;
use App\Entity\User;
use App\Tests\Support\InMemoryAuthClientRegistryRepository;
use App\Tests\Support\InMemoryUserRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BootstrapInitialAuthCommandTest extends TestCase
{
    public function testExecuteCreatesAdminAndDefaultClientsWhenMissing(): void
    {
        $users = new InMemoryUserRepository();
        $clients = new InMemoryAuthClientRegistryRepository();
        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->expects(self::once())
            ->method('hashPassword')
            ->with(
                self::callback(static fn (object $user): bool => $user instanceof User && $user->getEmail() === 'admin@retaia.local'),
                'Admin-pass-123!'
            )
            ->willReturn('persisted-admin-hash');

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
        $agentClient = $clients->findByClientId('agent-default');
        $mcpClient = $clients->findByClientId('mcp-default');

        self::assertInstanceOf(User::class, $admin);
        self::assertContains('ROLE_ADMIN', $admin->getRoles());
        self::assertTrue($admin->isEmailVerified());
        self::assertNotEmpty($admin->getPassword());
        self::assertSame('persisted-admin-hash', $admin->getPassword());

        self::assertSame('agent-secret-test', $agentClient?->secretKey);
        self::assertSame('AGENT', $agentClient?->clientKind);
        self::assertSame('mcp-secret-test', $mcpClient?->secretKey);
        self::assertSame('MCP', $mcpClient?->clientKind);
    }

    public function testExecuteLeavesExistingSecretsUnchangedWithoutFlags(): void
    {
        $existingAdmin = new User('admin-1', 'admin@retaia.local', 'existing-hash', ['ROLE_ADMIN'], true);
        $users = new InMemoryUserRepository($existingAdmin);
        $clients = new InMemoryAuthClientRegistryRepository(
            new AuthClientRegistryEntry('agent-default', 'AGENT', 'persisted-agent-secret', null, null, null, null, null),
            new AuthClientRegistryEntry('mcp-default', 'MCP', 'persisted-mcp-secret', null, null, null, null, null),
        );
        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->expects(self::never())->method('hashPassword');

        $command = new BootstrapInitialAuthCommand($users, $passwordHasher, $clients);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $agentClient = $clients->findByClientId('agent-default');
        $mcpClient = $clients->findByClientId('mcp-default');
        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('(unchanged)', $tester->getDisplay());
        self::assertSame('persisted-agent-secret', $agentClient?->secretKey);
        self::assertSame('persisted-mcp-secret', $mcpClient?->secretKey);
        $adminUser = $users->findByEmail('admin@retaia.local');
        self::assertSame('existing-hash', $adminUser?->getPassword());
        self::assertContains('ROLE_ADMIN', $adminUser?->getRoles() ?? []);
        self::assertTrue($adminUser?->isEmailVerified() ?? false);
    }
}
