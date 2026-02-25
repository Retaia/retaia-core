<?php

namespace App\Tests\Functional;

use App\Entity\Asset;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class IngestEnqueueStableCommandTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    public function testStableFilesAreQueuedIntoAssetsAndJobs(): void
    {
        $root = sys_get_temp_dir().'/retaia-enqueue-ok-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/new-rush.mov', 'ok');
        putenv('APP_INGEST_WATCH_PATH='.$root.'/INBOX');
        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        static::ensureKernelShutdown();

        static::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureTables($connection);

        $connection->insert('ingest_scan_file', [
            'path' => 'INBOX/new-rush.mov',
            'size_bytes' => 1234,
            'mtime' => '2026-02-10 12:00:00',
            'stable_count' => 2,
            'status' => 'stable',
            'first_seen_at' => '2026-02-10 12:00:00',
            'last_seen_at' => '2026-02-10 12:01:00',
        ]);

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:enqueue-stable');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 10]);
        self::assertStringContainsString('Queued 1 stable file(s). Missing: 0.', $tester->getDisplay());

        $jobCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM processing_job');
        self::assertSame(1, $jobCount);
        $scanStatus = (string) $connection->fetchOne('SELECT status FROM ingest_scan_file WHERE path = :path', ['path' => 'INBOX/new-rush.mov']);
        self::assertSame('queued', $scanStatus);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $assetUuid = $this->assetUuidFromPath('INBOX/new-rush.mov');
        $asset = $entityManager->find(Asset::class, $assetUuid);
        self::assertInstanceOf(Asset::class, $asset);
        self::assertSame('new-rush.mov', $asset->getFilename());
        self::assertSame('nas-main', $asset->getFields()['paths']['storage_id'] ?? null);
        self::assertSame('INBOX/new-rush.mov', $asset->getFields()['paths']['original_relative'] ?? null);
    }

    public function testMissingStableFileIsMarkedMissingAndNotQueued(): void
    {
        $root = sys_get_temp_dir().'/retaia-enqueue-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        putenv('APP_INGEST_WATCH_PATH='.$root.'/INBOX');
        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        static::ensureKernelShutdown();

        static::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureTables($connection);

        $connection->insert('ingest_scan_file', [
            'path' => 'INBOX/missing-rush.mov',
            'size_bytes' => 50,
            'mtime' => '2026-02-10 12:00:00',
            'stable_count' => 2,
            'status' => 'stable',
            'first_seen_at' => '2026-02-10 12:00:00',
            'last_seen_at' => '2026-02-10 12:01:00',
        ]);

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:enqueue-stable');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 10]);
        self::assertStringContainsString('Missing: 1.', $tester->getDisplay());

        $jobCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM processing_job');
        self::assertSame(0, $jobCount);
        $scanStatus = (string) $connection->fetchOne('SELECT status FROM ingest_scan_file WHERE path = :path', ['path' => 'INBOX/missing-rush.mov']);
        self::assertSame('missing', $scanStatus);
    }

    private function ensureTables(Connection $connection): void
    {
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
    }

    private function assetUuidFromPath(string $path): string
    {
        $hex = md5($path);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
