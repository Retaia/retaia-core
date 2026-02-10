<?php

namespace App\Tests\Unit\Command;

use App\Command\OpsReadinessCheckCommand;
use App\Ingest\Service\WatchPathResolver;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OpsReadinessCheckCommandTest extends TestCase
{
    public function testCommandSucceedsWhenReadinessChecksPass(): void
    {
        $root = sys_get_temp_dir().'/retaia-readiness-ok-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $resolver = new WatchPathResolver('/', $root.'/INBOX');
        $command = new OpsReadinessCheckCommand($connection, $resolver, 'dev', '');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Ops readiness checks passed', $tester->getDisplay());
    }

    public function testCommandFailsWhenIngestDirectoriesMissing(): void
    {
        $root = sys_get_temp_dir().'/retaia-readiness-missing-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $resolver = new WatchPathResolver('/', $root.'/INBOX');
        $command = new OpsReadinessCheckCommand($connection, $resolver, 'dev', '');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Missing ingest directory', $tester->getDisplay());
    }

    public function testCommandFailsInProdWithInvalidSentryDsn(): void
    {
        $root = sys_get_temp_dir().'/retaia-readiness-sentry-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $resolver = new WatchPathResolver('/', $root.'/INBOX');
        $command = new OpsReadinessCheckCommand($connection, $resolver, 'prod', 'https://token@example.com/1');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('SENTRY_DSN is missing or invalid', $tester->getDisplay());
    }
}
