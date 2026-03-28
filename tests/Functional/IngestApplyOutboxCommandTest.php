<?php

namespace App\Tests\Functional;

use App\Asset\AssetState;
use App\Entity\Asset;
use App\Tests\Support\BusinessStorageEnvTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class IngestApplyOutboxCommandTest extends KernelTestCase
{
    use RecreateDatabaseTrait;
    use BusinessStorageEnvTrait;

    public function testArchivedFileIsMovedAndAudited(): void
    {
        $root = sys_get_temp_dir().'/retaia-move-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/rush.mov', 'data');

        $this->configureSingleLocalBusinessStorage($root);

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
            [
                'paths' => [
                    'storage_id' => 'nas-main',
                    'original_relative' => 'INBOX/rush.mov',
                    'sidecars_relative' => [],
                ],
            ]
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
        self::assertSame('ARCHIVE/rush.mov', $fields['paths']['original_relative'] ?? null);
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

        $this->configureSingleLocalBusinessStorage($root);

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
            [
                'paths' => [
                    'storage_id' => 'nas-main',
                    'original_relative' => 'INBOX/a/rush.mov',
                    'sidecars_relative' => [],
                ],
            ]
        );
        $assetTwo = new Asset(
            'aaaaaa22-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'VIDEO',
            'rush.mov',
            AssetState::ARCHIVED,
            [],
            null,
            [
                'paths' => [
                    'storage_id' => 'nas-main',
                    'original_relative' => 'INBOX/b/rush.mov',
                    'sidecars_relative' => [],
                ],
            ]
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

        $this->configureSingleLocalBusinessStorage($root);

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
                [
                    'paths' => [
                        'storage_id' => 'nas-main',
                        'original_relative' => sprintf('INBOX/%02d/rush.mov', $i),
                        'sidecars_relative' => [],
                    ],
                ]
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

        $this->configureSingleLocalBusinessStorage($root);

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
                'paths' => [
                    'storage_id' => 'nas-main',
                    'original_relative' => 'INBOX/rush.mov',
                    'sidecars_relative' => [],
                ],
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

    public function testArchivedMoveAlsoMovesDerivedAndUpdatesMetadata(): void
    {
        $root = sys_get_temp_dir().'/retaia-move-derived-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        mkdir($root.'/.derived/ffffffff-ffff-ffff-ffff-ffffffffffff', 0777, true);
        file_put_contents($root.'/INBOX/clip.mov', 'data');
        file_put_contents($root.'/.derived/ffffffff-ffff-ffff-ffff-ffffffffffff/proxy.mp4', 'proxy');

        $this->configureSingleLocalBusinessStorage($root);

        static::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureAuditTable($connection);

        $asset = new Asset(
            'ffffffff-ffff-ffff-ffff-ffffffffffff',
            'VIDEO',
            'clip.mov',
            AssetState::ARCHIVED,
            [],
            null,
            [
                'paths' => [
                    'storage_id' => 'nas-main',
                    'original_relative' => 'INBOX/clip.mov',
                    'sidecars_relative' => ['.derived/ffffffff-ffff-ffff-ffff-ffffffffffff/proxy.mp4'],
                ],
                'derived' => [
                    'derived_manifest' => [
                        ['kind' => 'proxy_video', 'ref' => '.derived/ffffffff-ffff-ffff-ffff-ffffffffffff/proxy.mp4'],
                    ],
                ],
            ]
        );
        $entityManager->persist($asset);
        $entityManager->flush();

        $connection->insert('asset_derived_file', [
            'id' => bin2hex(random_bytes(8)),
            'asset_uuid' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
            'kind' => 'proxy_video',
            'content_type' => 'video/mp4',
            'size_bytes' => 5,
            'sha256' => null,
            'storage_path' => '.derived/ffffffff-ffff-ffff-ffff-ffffffffffff/proxy.mp4',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:apply-outbox');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 10]);

        self::assertFileExists($root.'/ARCHIVE/clip.mov');
        self::assertFileDoesNotExist($root.'/INBOX/clip.mov');
        self::assertFileExists($root.'/ARCHIVE/.derived/ffffffff-ffff-ffff-ffff-ffffffffffff/proxy.mp4');
        self::assertFileDoesNotExist($root.'/.derived/ffffffff-ffff-ffff-ffff-ffffffffffff/proxy.mp4');

        $storagePath = (string) $connection->fetchOne(
            'SELECT storage_path FROM asset_derived_file WHERE asset_uuid = :assetUuid LIMIT 1',
            ['assetUuid' => 'ffffffff-ffff-ffff-ffff-ffffffffffff']
        );
        self::assertSame('ARCHIVE/.derived/ffffffff-ffff-ffff-ffff-ffffffffffff/proxy.mp4', $storagePath);

        $assetReloaded = $entityManager->find(Asset::class, 'ffffffff-ffff-ffff-ffff-ffffffffffff');
        self::assertInstanceOf(Asset::class, $assetReloaded);
        $fields = $assetReloaded->getFields();
        self::assertContains('ARCHIVE/.derived/ffffffff-ffff-ffff-ffff-ffffffffffff/proxy.mp4', $fields['paths']['sidecars_relative'] ?? []);
        self::assertSame(
            'ARCHIVE/.derived/ffffffff-ffff-ffff-ffff-ffffffffffff/proxy.mp4',
            $fields['derived']['derived_manifest'][0]['ref'] ?? null
        );
    }

    public function testApplyOutboxMovesDerivedForBothKeepAndRejectTargets(): void
    {
        $root = sys_get_temp_dir().'/retaia-move-derived-both-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        mkdir($root.'/.derived/11111111-1111-4111-8111-111111111111', 0777, true);
        mkdir($root.'/.derived/22222222-2222-4222-8222-222222222222', 0777, true);
        file_put_contents($root.'/INBOX/keep.mov', 'keep');
        file_put_contents($root.'/INBOX/reject.mov', 'reject');
        file_put_contents($root.'/.derived/11111111-1111-4111-8111-111111111111/proxy.mp4', 'kproxy');
        file_put_contents($root.'/.derived/22222222-2222-4222-8222-222222222222/proxy.mp4', 'rproxy');

        $this->configureSingleLocalBusinessStorage($root);

        static::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureAuditTable($connection);

        $keepUuid = '11111111-1111-4111-8111-111111111111';
        $rejectUuid = '22222222-2222-4222-8222-222222222222';

        $keepAsset = new Asset(
            $keepUuid,
            'VIDEO',
            'keep.mov',
            AssetState::ARCHIVED,
            [],
            null,
            [
                'paths' => [
                    'storage_id' => 'nas-main',
                    'original_relative' => 'INBOX/keep.mov',
                    'sidecars_relative' => ['.derived/'.$keepUuid.'/proxy.mp4'],
                ],
                'derived' => [
                    'derived_manifest' => [
                        ['kind' => 'proxy_video', 'ref' => '.derived/'.$keepUuid.'/proxy.mp4'],
                    ],
                ],
            ]
        );
        $rejectAsset = new Asset(
            $rejectUuid,
            'VIDEO',
            'reject.mov',
            AssetState::REJECTED,
            [],
            null,
            [
                'paths' => [
                    'storage_id' => 'nas-main',
                    'original_relative' => 'INBOX/reject.mov',
                    'sidecars_relative' => ['.derived/'.$rejectUuid.'/proxy.mp4'],
                ],
                'derived' => [
                    'derived_manifest' => [
                        ['kind' => 'proxy_video', 'ref' => '.derived/'.$rejectUuid.'/proxy.mp4'],
                    ],
                ],
            ]
        );
        $entityManager->persist($keepAsset);
        $entityManager->persist($rejectAsset);
        $entityManager->flush();

        foreach ([[$keepUuid, '.derived/'.$keepUuid.'/proxy.mp4'], [$rejectUuid, '.derived/'.$rejectUuid.'/proxy.mp4']] as [$uuid, $path]) {
            $connection->insert('asset_derived_file', [
                'id' => bin2hex(random_bytes(8)),
                'asset_uuid' => $uuid,
                'kind' => 'proxy_video',
                'content_type' => 'video/mp4',
                'size_bytes' => 6,
                'sha256' => null,
                'storage_path' => $path,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:apply-outbox');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 20]);

        self::assertFileExists($root.'/ARCHIVE/keep.mov');
        self::assertFileExists($root.'/REJECTS/reject.mov');
        self::assertFileExists($root.'/ARCHIVE/.derived/'.$keepUuid.'/proxy.mp4');
        self::assertFileExists($root.'/REJECTS/.derived/'.$rejectUuid.'/proxy.mp4');
        self::assertFileDoesNotExist($root.'/.derived/'.$keepUuid.'/proxy.mp4');
        self::assertFileDoesNotExist($root.'/.derived/'.$rejectUuid.'/proxy.mp4');

        $keepStored = (string) $connection->fetchOne(
            'SELECT storage_path FROM asset_derived_file WHERE asset_uuid = :assetUuid LIMIT 1',
            ['assetUuid' => $keepUuid]
        );
        $rejectStored = (string) $connection->fetchOne(
            'SELECT storage_path FROM asset_derived_file WHERE asset_uuid = :assetUuid LIMIT 1',
            ['assetUuid' => $rejectUuid]
        );
        self::assertSame('ARCHIVE/.derived/'.$keepUuid.'/proxy.mp4', $keepStored);
        self::assertSame('REJECTS/.derived/'.$rejectUuid.'/proxy.mp4', $rejectStored);

        $entityManager->clear();
        $keepReloaded = $entityManager->find(Asset::class, $keepUuid);
        $rejectReloaded = $entityManager->find(Asset::class, $rejectUuid);
        self::assertInstanceOf(Asset::class, $keepReloaded);
        self::assertInstanceOf(Asset::class, $rejectReloaded);
        self::assertContains('ARCHIVE/.derived/'.$keepUuid.'/proxy.mp4', $keepReloaded->getFields()['paths']['sidecars_relative'] ?? []);
        self::assertContains('REJECTS/.derived/'.$rejectUuid.'/proxy.mp4', $rejectReloaded->getFields()['paths']['sidecars_relative'] ?? []);
    }

    public function testAssetFailureDoesNotBlockOtherAssets(): void
    {
        $root = sys_get_temp_dir().'/retaia-move-failure-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/a.mov', 'archive-fail');
        file_put_contents($root.'/INBOX/b.mov', 'reject-ok');

        $this->configureSingleLocalBusinessStorage($root);

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
            [
                'paths' => [
                    'storage_id' => 'unknown-storage',
                    'original_relative' => 'INBOX/a.mov',
                    'sidecars_relative' => [],
                ],
            ]
        );
        $rejected = new Asset(
            'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
            'VIDEO',
            'b.mov',
            AssetState::REJECTED,
            [],
            null,
            [
                'paths' => [
                    'storage_id' => 'nas-main',
                    'original_relative' => 'INBOX/b.mov',
                    'sidecars_relative' => [],
                ],
            ]
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
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS asset_derived_file (
                id VARCHAR(16) PRIMARY KEY NOT NULL,
                asset_uuid VARCHAR(36) NOT NULL,
                kind VARCHAR(64) NOT NULL,
                content_type VARCHAR(128) NOT NULL,
                size_bytes INTEGER NOT NULL,
                sha256 VARCHAR(64) DEFAULT NULL,
                storage_path VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL
            )'
        );
    }
}
