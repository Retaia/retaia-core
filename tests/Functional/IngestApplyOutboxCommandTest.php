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

    public function testHighVolumeCollisionsDoNotOverwriteAndMoveAllFiles(): void
    {
        $root = sys_get_temp_dir().'/retaia-move-collision-massive-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);

        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';

        static::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureAuditTable($connection);

        $assetCount = 16;
        for ($i = 1; $i <= $assetCount; ++$i) {
            $folder = sprintf('%s/INBOX/%02d', $root, $i);
            mkdir($folder, 0777, true);
            file_put_contents($folder.'/rush.mov', sprintf('payload-%02d', $i));

            $asset = new Asset(
                sprintf('%08d-aaaa-4aaa-8aaa-%012d', $i, $i),
                'VIDEO',
                'rush.mov',
                AssetState::ARCHIVED,
                [],
                null,
                ['source_path' => sprintf('INBOX/%02d/rush.mov', $i)]
            );
            $entityManager->persist($asset);
        }
        $entityManager->flush();

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:apply-outbox');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 100]);

        $archiveFiles = glob($root.'/ARCHIVE/rush*.mov');
        self::assertIsArray($archiveFiles);
        self::assertCount($assetCount, $archiveFiles);

        $basenames = array_map('basename', $archiveFiles);
        self::assertCount($assetCount, array_unique($basenames));

        $contents = array_map(static fn (string $file): string => (string) file_get_contents($file), $archiveFiles);
        sort($contents);
        $expected = [];
        for ($i = 1; $i <= $assetCount; ++$i) {
            $expected[] = sprintf('payload-%02d', $i);
        }
        self::assertSame($expected, $contents);
    }

    public function testRetryDoesNotDuplicatePathHistoryWhenTargetAlreadyExists(): void
    {
        $root = sys_get_temp_dir().'/retaia-move-retry-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/ARCHIVE/rush.mov', 'data');

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
            'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'VIDEO',
            'rush.mov',
            AssetState::ARCHIVED,
            [],
            null,
            [
                'source_path' => 'INBOX/rush.mov',
                'current_path' => 'ARCHIVE/rush.mov',
                'path_history' => [
                    [
                        'from' => 'INBOX/rush.mov',
                        'to' => 'ARCHIVE/rush.mov',
                        'reason' => 'state_transition',
                        'moved_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    ],
                ],
            ]
        );
        $entityManager->persist($asset);
        $entityManager->flush();

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:apply-outbox');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 10]);

        $assetReloaded = $entityManager->find(Asset::class, 'cccccccc-cccc-cccc-cccc-cccccccccccc');
        self::assertInstanceOf(Asset::class, $assetReloaded);
        $history = $assetReloaded->getFields()['path_history'] ?? null;
        self::assertIsArray($history);
        self::assertCount(1, $history);

        $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM ingest_path_audit');
        self::assertSame(0, $count);
    }

    public function testMoveFailureDoesNotBlockOtherAssets(): void
    {
        $root = sys_get_temp_dir().'/retaia-move-failure-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/a.mov', 'archive-fail');
        file_put_contents($root.'/INBOX/b.mov', 'reject-ok');
        chmod($root.'/ARCHIVE', 0555);

        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';

        static::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureAuditTable($connection);

        $archived = new Asset(
            'dddddddd-dddd-dddd-dddd-dddddddddddd',
            'VIDEO',
            'a.mov',
            AssetState::ARCHIVED,
            [],
            null,
            ['source_path' => 'INBOX/a.mov']
        );
        $rejected = new Asset(
            'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
            'VIDEO',
            'b.mov',
            AssetState::REJECTED,
            [],
            null,
            ['source_path' => 'INBOX/b.mov']
        );
        $entityManager->persist($archived);
        $entityManager->persist($rejected);
        $entityManager->flush();

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:apply-outbox');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 10]);

        self::assertFileExists($root.'/INBOX/a.mov');
        self::assertFileExists($root.'/REJECTS/b.mov');
        self::assertStringContainsString('Encountered 1 move failure', $tester->getDisplay());

        chmod($root.'/ARCHIVE', 0755);
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
