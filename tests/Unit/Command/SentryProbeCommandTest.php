<?php

namespace App\Tests\Unit\Command;

use App\Command\SentryProbeCommand;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\EventId;
use Sentry\State\HubInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SentryProbeCommandTest extends TestCase
{
    public function testFailsWhenDsnIsMissingOrInvalid(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('captureMessage');

        $command = new SentryProbeCommand($hub, 'prod', '');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('SENTRY_DSN is missing or invalid', $tester->getDisplay());
    }

    public function testSendsProbeInProdWhenDsnIsValid(): void
    {
        $eventId = EventId::generate();
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())->method('flush');

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('captureMessage')->willReturn($eventId);
        $hub->expects(self::once())->method('getClient')->willReturn($client);

        $command = new SentryProbeCommand($hub, 'prod', 'https://token@sentry.fullfrontend.be/1');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--message' => 'probe']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Sentry probe sent', $tester->getDisplay());
    }

    public function testSkipsOutsideProdByDefault(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('captureMessage');

        $command = new SentryProbeCommand($hub, 'dev', 'https://token@sentry.fullfrontend.be/1');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Skipped: current env is "dev"', $tester->getDisplay());
    }
}

