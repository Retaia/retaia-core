<?php

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class IngestPipelineE2ETest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    public function testTwoPollsThenEnqueueIsStableAndIdempotent(): void
    {
        $root = sys_get_temp_dir().'/retaia-pipeline-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/e2e.mov', 'payload');

        putenv('APP_INGEST_WATCH_PATH='.$root.'/INBOX');
        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        static::ensureKernelShutdown();

        static::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureTables($connection);

        $application = new Application(static::$kernel);
        $poll = new CommandTester($application->find('app:ingest:poll'));
        $enqueue = new CommandTester($application->find('app:ingest:enqueue-stable'));

        $poll->execute(['--limit' => 10]);
        $poll->execute(['--limit' => 10]);
        $scan = $connection->fetchAssociative('SELECT stable_count, status FROM ingest_scan_file WHERE path = :path', [
            'path' => 'INBOX/e2e.mov',
        ]);
        self::assertIsArray($scan);
        self::assertSame(2, (int) ($scan['stable_count'] ?? 0));
        self::assertSame('stable', (string) ($scan['status'] ?? ''));

        $enqueue->execute(['--limit' => 10]);
        $enqueue->execute(['--limit' => 10]);

        $jobCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM processing_job');
        self::assertSame(1, $jobCount);
        $status = (string) $connection->fetchOne('SELECT status FROM ingest_scan_file WHERE path = :path', ['path' => 'INBOX/e2e.mov']);
        self::assertSame('queued', $status);
    }

    public function testRenameBetweenPollsDoesNotCrashAndDoesNotQueueUnstableFile(): void
    {
        $root = sys_get_temp_dir().'/retaia-pipeline-rename-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/source.mov', 'payload');

        putenv('APP_INGEST_WATCH_PATH='.$root.'/INBOX');
        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        static::ensureKernelShutdown();

        static::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureTables($connection);

        $application = new Application(static::$kernel);
        $poll = new CommandTester($application->find('app:ingest:poll'));
        $enqueue = new CommandTester($application->find('app:ingest:enqueue-stable'));

        $poll->execute(['--limit' => 10]);
        rename($root.'/INBOX/source.mov', $root.'/INBOX/renamed.mov');
        $poll->execute(['--limit' => 10]);

        $sourceStatus = (string) $connection->fetchOne('SELECT status FROM ingest_scan_file WHERE path = :path', ['path' => 'INBOX/source.mov']);
        $renamedStatus = (string) $connection->fetchOne('SELECT status FROM ingest_scan_file WHERE path = :path', ['path' => 'INBOX/renamed.mov']);
        self::assertSame('discovered', $sourceStatus);
        self::assertSame('discovered', $renamedStatus);

        $enqueue->execute(['--limit' => 10]);
        $jobCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM processing_job');
        self::assertSame(0, $jobCount);
    }

    private function ensureTables(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS ingest_scan_file (
                path VARCHAR(1024) PRIMARY KEY NOT NULL,
                size_bytes INTEGER NOT NULL,
                mtime DATETIME NOT NULL,
                stable_count INTEGER NOT NULL,
                status VARCHAR(32) NOT NULL,
                first_seen_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL
            )'
        );
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS processing_job (
                id VARCHAR(36) PRIMARY KEY NOT NULL,
                asset_uuid VARCHAR(36) NOT NULL,
                job_type VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL,
                claimed_by VARCHAR(32) DEFAULT NULL,
                lock_token VARCHAR(64) DEFAULT NULL,
                locked_until DATETIME DEFAULT NULL,
                result_payload CLOB DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )'
        );
    }
}
