<?php

namespace App\Tests\Functional;

use App\Asset\AssetState;
use App\Entity\Asset;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class IngestApplyOutboxCommandTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    public function testArchivedFileIsMovedAndAudited(): void
    {
        $root = sys_get_temp_dir().'/retaia-move-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/rush.mov', 'data');

        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';

        static::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureAuditTable($connection);

        $asset = new Asset(
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'VIDEO',
            'rush.mov',
            AssetState::ARCHIVED,
            [],
            null,
            ['source_path' => 'INBOX/rush.mov']
        );
        $entityManager->persist($asset);
        $entityManager->flush();

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:apply-outbox');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 10]);

        self::assertFileDoesNotExist($root.'/INBOX/rush.mov');
        self::assertFileExists($root.'/ARCHIVE/rush.mov');
        $assetReloaded = $entityManager->find(Asset::class, 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
        self::assertInstanceOf(Asset::class, $assetReloaded);
        $fields = $assetReloaded->getFields();
        self::assertSame('ARCHIVE/rush.mov', $fields['current_path'] ?? null);
        self::assertIsArray($fields['path_history'] ?? null);
        self::assertSame(1, count($fields['path_history']));

        $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM ingest_path_audit');
        self::assertSame(1, $count);
    }

    public function testMassiveFilenameCollisionsGenerateDeterministicUniqueTargets(): void
    {
        $root = sys_get_temp_dir().'/retaia-move-collision-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX/a', 0777, true);
        mkdir($root.'/INBOX/b', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/a/rush.mov', 'first');
        file_put_contents($root.'/INBOX/b/rush.mov', 'second');

        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';

        static::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureAuditTable($connection);

        $assetOne = new Asset(
            'aaaaaa11-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'VIDEO',
            'rush.mov',
            AssetState::ARCHIVED,
            [],
            null,
            ['source_path' => 'INBOX/a/rush.mov']
        );
        $assetTwo = new Asset(
            'aaaaaa22-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'VIDEO',
            'rush.mov',
            AssetState::ARCHIVED,
            [],
            null,
            ['source_path' => 'INBOX/b/rush.mov']
        );
        $entityManager->persist($assetOne);
        $entityManager->persist($assetTwo);
        $entityManager->flush();

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:apply-outbox');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 20]);

        $archiveFiles = glob($root.'/ARCHIVE/rush*.mov');
        self::assertIsArray($archiveFiles);
        self::assertCount(2, $archiveFiles);

        $contents = array_map(static fn (string $file): string => (string) file_get_contents($file), $archiveFiles);
        sort($contents);
        self::assertSame(['first', 'second'], $contents);
    }

    private function ensureAuditTable(Connection $connection): void
    {
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
