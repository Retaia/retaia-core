<?php

namespace App\Tests\Functional;

use App\Tests\Support\BusinessStorageEnvTrait;
use Doctrine\DBAL\Connection;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class IngestCronTickCommandTest extends KernelTestCase
{
    use RecreateDatabaseTrait;
    use BusinessStorageEnvTrait;

    public function testCronTickRunsPipelineCycle(): void
    {
        $root = sys_get_temp_dir().'/retaia-cron-tick-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/tick.mov', 'payload');

        $this->configureSingleLocalBusinessStorage($root);
        static::ensureKernelShutdown();

        static::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureTables($connection);

        $application = new Application(static::$kernel);
        $command = new CommandTester($application->find('app:ingest:cron-tick'));

        $command->execute(['--poll-limit' => 10, '--enqueue-limit' => 10, '--apply-limit' => 10]);
        $command->execute(['--poll-limit' => 10, '--enqueue-limit' => 10, '--apply-limit' => 10]);

        $jobCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM processing_job');
        self::assertSame(3, $jobCount);

        $status = (string) $connection->fetchOne(
            'SELECT status FROM ingest_scan_file WHERE storage_id = :storageId AND path = :path',
            ['storageId' => 'nas-main', 'path' => 'INBOX/tick.mov']
        );
        self::assertSame('queued', $status);
        self::assertStringContainsString('Ingest cron tick completed.', $command->getDisplay());
    }

    private function ensureTables(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS ingest_scan_file (
                storage_id VARCHAR(64) NOT NULL,
                path VARCHAR(1024) NOT NULL,
                size_bytes INTEGER NOT NULL,
                mtime DATETIME NOT NULL,
                stable_count INTEGER NOT NULL,
                status VARCHAR(32) NOT NULL,
                first_seen_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                PRIMARY KEY (storage_id, path)
            )'
        );
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS processing_job (
                id VARCHAR(36) PRIMARY KEY NOT NULL,
                asset_uuid VARCHAR(36) NOT NULL,
                job_type VARCHAR(64) NOT NULL,
                state_version VARCHAR(64) NOT NULL DEFAULT \'1\',
                status VARCHAR(16) NOT NULL,
                correlation_id VARCHAR(64) DEFAULT NULL,
                claimed_by VARCHAR(32) DEFAULT NULL,
                claimed_at DATETIME DEFAULT NULL,
                lock_token VARCHAR(64) DEFAULT NULL,
                fencing_token INTEGER DEFAULT NULL,
                locked_until DATETIME DEFAULT NULL,
                completed_by VARCHAR(32) DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL,
                failed_by VARCHAR(32) DEFAULT NULL,
                failed_at DATETIME DEFAULT NULL,
                result_payload CLOB DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )'
        );
        $connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_processing_job_asset_type_version ON processing_job (asset_uuid, job_type, state_version)');
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS ingest_path_audit (
                id VARCHAR(32) PRIMARY KEY NOT NULL,
                asset_uuid VARCHAR(36) NOT NULL,
                from_path VARCHAR(1024) NOT NULL,
                to_path VARCHAR(1024) NOT NULL,
                reason VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL
            )'
        );
    }
}
