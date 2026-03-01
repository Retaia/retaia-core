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
        self::assertStringContainsString('Queued 3 stable file(s). Missing: 0. Unmatched sidecars: 0.', $tester->getDisplay());

        $jobCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM processing_job');
        self::assertSame(3, $jobCount);
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
        self::assertStringContainsString('Missing: 1. Unmatched sidecars: 0.', $tester->getDisplay());

        $jobCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM processing_job');
        self::assertSame(0, $jobCount);
        $scanStatus = (string) $connection->fetchOne('SELECT status FROM ingest_scan_file WHERE path = :path', ['path' => 'INBOX/missing-rush.mov']);
        self::assertSame('missing', $scanStatus);
    }

    public function testImageFileIsEnqueuedAsPhotoMediaType(): void
    {
        $root = sys_get_temp_dir().'/retaia-enqueue-photo-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/shot.jpg', 'ok');
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
            'path' => 'INBOX/shot.jpg',
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
        self::assertStringContainsString('Queued 3 stable file(s). Missing: 0. Unmatched sidecars: 0.', $tester->getDisplay());

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $assetUuid = $this->assetUuidFromPath('INBOX/shot.jpg');
        $asset = $entityManager->find(Asset::class, $assetUuid);
        self::assertInstanceOf(Asset::class, $asset);
        self::assertSame('PHOTO', $asset->getMediaType());
    }

    public function testRawAndJpegPairUsesRawAsMainAssetAndMarksProxyDone(): void
    {
        $root = sys_get_temp_dir().'/retaia-enqueue-raw-jpg-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/shot.cr2', 'raw');
        file_put_contents($root.'/INBOX/shot.jpg', 'jpg');
        putenv('APP_INGEST_WATCH_PATH='.$root.'/INBOX');
        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        static::ensureKernelShutdown();

        static::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureTables($connection);

        foreach (['INBOX/shot.cr2', 'INBOX/shot.jpg'] as $path) {
            $connection->insert('ingest_scan_file', [
                'path' => $path,
                'size_bytes' => 100,
                'mtime' => '2026-02-10 12:00:00',
                'stable_count' => 2,
                'status' => 'stable',
                'first_seen_at' => '2026-02-10 12:00:00',
                'last_seen_at' => '2026-02-10 12:01:00',
            ]);
        }

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:enqueue-stable');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 20]);

        $jobCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM processing_job');
        self::assertSame(2, $jobCount);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $rawAssetUuid = $this->assetUuidFromPath('INBOX/shot.cr2');
        $jpgAssetUuid = $this->assetUuidFromPath('INBOX/shot.jpg');

        $rawAsset = $entityManager->find(Asset::class, $rawAssetUuid);
        self::assertInstanceOf(Asset::class, $rawAsset);
        self::assertSame('PHOTO', $rawAsset->getMediaType());
        self::assertTrue((bool) ($rawAsset->getFields()['proxy_done'] ?? false));

        $jpgAsset = $entityManager->find(Asset::class, $jpgAssetUuid);
        self::assertNull($jpgAsset);

        $proxyDerivedCount = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM asset_derived_file WHERE asset_uuid = :assetUuid AND kind = :kind',
            ['assetUuid' => $rawAssetUuid, 'kind' => 'proxy_photo']
        );
        self::assertSame(1, $proxyDerivedCount);
        $storagePath = (string) $connection->fetchOne(
            'SELECT storage_path FROM asset_derived_file WHERE asset_uuid = :assetUuid AND kind = :kind LIMIT 1',
            ['assetUuid' => $rawAssetUuid, 'kind' => 'proxy_photo']
        );
        self::assertStringStartsWith('.derived/'.$rawAssetUuid.'/', $storagePath);
        self::assertFileExists($root.'/'.$storagePath);
        self::assertFileDoesNotExist($root.'/INBOX/shot.jpg');
        self::assertContains($storagePath, $rawAsset->getFields()['paths']['sidecars_relative'] ?? []);
    }

    public function testLrfSidecarIsAttachedToOriginalAndNotQueuedAsStandaloneAsset(): void
    {
        $root = sys_get_temp_dir().'/retaia-enqueue-lrf-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/drone.mov', 'video');
        file_put_contents($root.'/INBOX/drone.lrf', 'proxy');
        putenv('APP_INGEST_WATCH_PATH='.$root.'/INBOX');
        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        static::ensureKernelShutdown();

        static::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureTables($connection);

        foreach (['INBOX/drone.mov', 'INBOX/drone.lrf'] as $path) {
            $connection->insert('ingest_scan_file', [
                'path' => $path,
                'size_bytes' => 100,
                'mtime' => '2026-02-10 12:00:00',
                'stable_count' => 2,
                'status' => 'stable',
                'first_seen_at' => '2026-02-10 12:00:00',
                'last_seen_at' => '2026-02-10 12:01:00',
            ]);
        }

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:enqueue-stable');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 20]);

        $jobCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM processing_job');
        self::assertSame(2, $jobCount);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $videoAssetUuid = $this->assetUuidFromPath('INBOX/drone.mov');
        $lrfAssetUuid = $this->assetUuidFromPath('INBOX/drone.lrf');

        $videoAsset = $entityManager->find(Asset::class, $videoAssetUuid);
        self::assertInstanceOf(Asset::class, $videoAsset);
        self::assertTrue((bool) ($videoAsset->getFields()['proxy_done'] ?? false));

        $lrfAsset = $entityManager->find(Asset::class, $lrfAssetUuid);
        self::assertNull($lrfAsset);

        $proxyDerivedCount = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM asset_derived_file WHERE asset_uuid = :assetUuid AND kind = :kind',
            ['assetUuid' => $videoAssetUuid, 'kind' => 'proxy_video']
        );
        self::assertSame(1, $proxyDerivedCount);
        $storagePath = (string) $connection->fetchOne(
            'SELECT storage_path FROM asset_derived_file WHERE asset_uuid = :assetUuid AND kind = :kind LIMIT 1',
            ['assetUuid' => $videoAssetUuid, 'kind' => 'proxy_video']
        );
        self::assertStringStartsWith('.derived/'.$videoAssetUuid.'/', $storagePath);
        self::assertStringEndsWith('.mp4', $storagePath);
        self::assertFileExists($root.'/'.$storagePath);
        self::assertFileDoesNotExist($root.'/INBOX/drone.lrf');
        self::assertContains($storagePath, $videoAsset->getFields()['paths']['sidecars_relative'] ?? []);
    }

    public function testProxyFolderFileIsAttachedToProjectOriginal(): void
    {
        $root = sys_get_temp_dir().'/retaia-enqueue-proxy-folder-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX/project/proxy', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/project/clip.mov', 'video');
        file_put_contents($root.'/INBOX/project/proxy/clip.mp4', 'proxy');
        putenv('APP_INGEST_WATCH_PATH='.$root.'/INBOX');
        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        static::ensureKernelShutdown();

        static::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureTables($connection);

        foreach (['INBOX/project/clip.mov', 'INBOX/project/proxy/clip.mp4'] as $path) {
            $connection->insert('ingest_scan_file', [
                'path' => $path,
                'size_bytes' => 100,
                'mtime' => '2026-02-10 12:00:00',
                'stable_count' => 2,
                'status' => 'stable',
                'first_seen_at' => '2026-02-10 12:00:00',
                'last_seen_at' => '2026-02-10 12:01:00',
            ]);
        }

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:enqueue-stable');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 20]);

        $jobCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM processing_job');
        self::assertSame(2, $jobCount);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $mainAssetUuid = $this->assetUuidFromPath('INBOX/project/clip.mov');
        $proxyAssetUuid = $this->assetUuidFromPath('INBOX/project/proxy/clip.mp4');

        $mainAsset = $entityManager->find(Asset::class, $mainAssetUuid);
        self::assertInstanceOf(Asset::class, $mainAsset);
        self::assertTrue((bool) ($mainAsset->getFields()['proxy_done'] ?? false));

        $proxyAsset = $entityManager->find(Asset::class, $proxyAssetUuid);
        self::assertNull($proxyAsset);

        $proxyDerivedCount = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM asset_derived_file WHERE asset_uuid = :assetUuid AND kind = :kind',
            ['assetUuid' => $mainAssetUuid, 'kind' => 'proxy_video']
        );
        self::assertSame(1, $proxyDerivedCount);
        $storagePath = (string) $connection->fetchOne(
            'SELECT storage_path FROM asset_derived_file WHERE asset_uuid = :assetUuid AND kind = :kind LIMIT 1',
            ['assetUuid' => $mainAssetUuid, 'kind' => 'proxy_video']
        );
        self::assertStringStartsWith('.derived/'.$mainAssetUuid.'/', $storagePath);
        self::assertFileExists($root.'/'.$storagePath);
        self::assertFileDoesNotExist($root.'/INBOX/project/proxy/clip.mp4');
        self::assertContains($storagePath, $mainAsset->getFields()['paths']['sidecars_relative'] ?? []);
    }

    public function testXmpSidecarIsAttachedToOriginalAndNotQueuedAsStandaloneAsset(): void
    {
        $root = sys_get_temp_dir().'/retaia-enqueue-xmp-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/shot.cr2', 'raw');
        file_put_contents($root.'/INBOX/shot.xmp', 'xmp');
        putenv('APP_INGEST_WATCH_PATH='.$root.'/INBOX');
        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        static::ensureKernelShutdown();

        static::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureTables($connection);

        foreach (['INBOX/shot.cr2', 'INBOX/shot.xmp'] as $path) {
            $connection->insert('ingest_scan_file', [
                'path' => $path,
                'size_bytes' => 100,
                'mtime' => '2026-02-10 12:00:00',
                'stable_count' => 2,
                'status' => 'stable',
                'first_seen_at' => '2026-02-10 12:00:00',
                'last_seen_at' => '2026-02-10 12:01:00',
            ]);
        }

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:enqueue-stable');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 20]);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $rawAssetUuid = $this->assetUuidFromPath('INBOX/shot.cr2');
        $xmpAssetUuid = $this->assetUuidFromPath('INBOX/shot.xmp');

        $rawAsset = $entityManager->find(Asset::class, $rawAssetUuid);
        self::assertInstanceOf(Asset::class, $rawAsset);
        self::assertContains('INBOX/shot.xmp', $rawAsset->getFields()['paths']['sidecars_relative'] ?? []);

        $xmpAsset = $entityManager->find(Asset::class, $xmpAssetUuid);
        self::assertNull($xmpAsset);
        self::assertFileExists($root.'/INBOX/shot.xmp');

        $rawJobCount = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM processing_job WHERE asset_uuid = :assetUuid',
            ['assetUuid' => $rawAssetUuid]
        );
        self::assertSame(3, $rawJobCount);
    }

    public function testSrtSidecarIsAttachedToOriginalAndNotQueuedAsStandaloneAsset(): void
    {
        $root = sys_get_temp_dir().'/retaia-enqueue-srt-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/interview.mov', 'video');
        file_put_contents($root.'/INBOX/interview.srt', "1\n00:00:00,000 --> 00:00:01,000\nHello\n");
        putenv('APP_INGEST_WATCH_PATH='.$root.'/INBOX');
        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        static::ensureKernelShutdown();

        static::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureTables($connection);

        foreach (['INBOX/interview.mov', 'INBOX/interview.srt'] as $path) {
            $connection->insert('ingest_scan_file', [
                'path' => $path,
                'size_bytes' => 100,
                'mtime' => '2026-02-10 12:00:00',
                'stable_count' => 2,
                'status' => 'stable',
                'first_seen_at' => '2026-02-10 12:00:00',
                'last_seen_at' => '2026-02-10 12:01:00',
            ]);
        }

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:enqueue-stable');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 20]);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $videoAssetUuid = $this->assetUuidFromPath('INBOX/interview.mov');
        $srtAssetUuid = $this->assetUuidFromPath('INBOX/interview.srt');

        $videoAsset = $entityManager->find(Asset::class, $videoAssetUuid);
        self::assertInstanceOf(Asset::class, $videoAsset);
        self::assertContains('INBOX/interview.srt', $videoAsset->getFields()['paths']['sidecars_relative'] ?? []);

        $srtAsset = $entityManager->find(Asset::class, $srtAssetUuid);
        self::assertNull($srtAsset);
        self::assertFileExists($root.'/INBOX/interview.srt');

        $videoJobCount = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM processing_job WHERE asset_uuid = :assetUuid',
            ['assetUuid' => $videoAssetUuid]
        );
        self::assertSame(3, $videoJobCount);
    }

    public function testExistingSrtIsAttachedWhenOnlyOriginalIsQueued(): void
    {
        $root = sys_get_temp_dir().'/retaia-enqueue-srt-existing-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/take.mov', 'video');
        file_put_contents($root.'/INBOX/take.srt', "1\n00:00:00,000 --> 00:00:01,000\nHi\n");
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
            'path' => 'INBOX/take.mov',
            'size_bytes' => 100,
            'mtime' => '2026-02-10 12:00:00',
            'stable_count' => 2,
            'status' => 'stable',
            'first_seen_at' => '2026-02-10 12:00:00',
            'last_seen_at' => '2026-02-10 12:01:00',
        ]);

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:enqueue-stable');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 20]);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $videoAssetUuid = $this->assetUuidFromPath('INBOX/take.mov');
        $videoAsset = $entityManager->find(Asset::class, $videoAssetUuid);
        self::assertInstanceOf(Asset::class, $videoAsset);
        self::assertContains('INBOX/take.srt', $videoAsset->getFields()['paths']['sidecars_relative'] ?? []);
    }

    public function testLegacyLrvAndThmSidecarsAreAttachedToVideoOriginal(): void
    {
        $root = sys_get_temp_dir().'/retaia-enqueue-legacy-sidecars-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/drone.mov', 'video');
        file_put_contents($root.'/INBOX/drone.lrv', 'legacy-proxy');
        file_put_contents($root.'/INBOX/drone.thm', 'thumb');
        putenv('APP_INGEST_VIDEO_LEGACY_SIDECARS_ENABLED=1');
        $_ENV['APP_INGEST_VIDEO_LEGACY_SIDECARS_ENABLED'] = '1';
        $_SERVER['APP_INGEST_VIDEO_LEGACY_SIDECARS_ENABLED'] = '1';
        putenv('APP_INGEST_WATCH_PATH='.$root.'/INBOX');
        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        static::ensureKernelShutdown();

        static::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureTables($connection);

        foreach (['INBOX/drone.mov', 'INBOX/drone.lrv', 'INBOX/drone.thm'] as $path) {
            $connection->insert('ingest_scan_file', [
                'path' => $path,
                'size_bytes' => 100,
                'mtime' => '2026-02-10 12:00:00',
                'stable_count' => 2,
                'status' => 'stable',
                'first_seen_at' => '2026-02-10 12:00:00',
                'last_seen_at' => '2026-02-10 12:01:00',
            ]);
        }

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:enqueue-stable');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 20]);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $videoAssetUuid = $this->assetUuidFromPath('INBOX/drone.mov');
        $lrvAssetUuid = $this->assetUuidFromPath('INBOX/drone.lrv');
        $thmAssetUuid = $this->assetUuidFromPath('INBOX/drone.thm');

        $videoAsset = $entityManager->find(Asset::class, $videoAssetUuid);
        self::assertInstanceOf(Asset::class, $videoAsset);
        self::assertContains('INBOX/drone.lrv', $videoAsset->getFields()['paths']['sidecars_relative'] ?? []);
        self::assertContains('INBOX/drone.thm', $videoAsset->getFields()['paths']['sidecars_relative'] ?? []);

        self::assertNull($entityManager->find(Asset::class, $lrvAssetUuid));
        self::assertNull($entityManager->find(Asset::class, $thmAssetUuid));
        self::assertFileExists($root.'/INBOX/drone.lrv');
        self::assertFileExists($root.'/INBOX/drone.thm');

        putenv('APP_INGEST_VIDEO_LEGACY_SIDECARS_ENABLED=0');
        $_ENV['APP_INGEST_VIDEO_LEGACY_SIDECARS_ENABLED'] = '0';
        $_SERVER['APP_INGEST_VIDEO_LEGACY_SIDECARS_ENABLED'] = '0';
    }

    public function testLegacyLrvSidecarIsIgnoredWhenFeatureDisabled(): void
    {
        $root = sys_get_temp_dir().'/retaia-enqueue-legacy-disabled-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/drone.mov', 'video');
        file_put_contents($root.'/INBOX/drone.lrv', 'legacy-proxy');
        putenv('APP_INGEST_VIDEO_LEGACY_SIDECARS_ENABLED=0');
        $_ENV['APP_INGEST_VIDEO_LEGACY_SIDECARS_ENABLED'] = '0';
        $_SERVER['APP_INGEST_VIDEO_LEGACY_SIDECARS_ENABLED'] = '0';
        putenv('APP_INGEST_WATCH_PATH='.$root.'/INBOX');
        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        static::ensureKernelShutdown();

        static::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureTables($connection);

        foreach (['INBOX/drone.mov', 'INBOX/drone.lrv'] as $path) {
            $connection->insert('ingest_scan_file', [
                'path' => $path,
                'size_bytes' => 100,
                'mtime' => '2026-02-10 12:00:00',
                'stable_count' => 2,
                'status' => 'stable',
                'first_seen_at' => '2026-02-10 12:00:00',
                'last_seen_at' => '2026-02-10 12:01:00',
            ]);
        }

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:enqueue-stable');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 20]);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $videoAssetUuid = $this->assetUuidFromPath('INBOX/drone.mov');
        $lrvAssetUuid = $this->assetUuidFromPath('INBOX/drone.lrv');

        $videoAsset = $entityManager->find(Asset::class, $videoAssetUuid);
        self::assertInstanceOf(Asset::class, $videoAsset);
        self::assertNotContains('INBOX/drone.lrv', $videoAsset->getFields()['paths']['sidecars_relative'] ?? []);
        self::assertNull($entityManager->find(Asset::class, $lrvAssetUuid));
    }

    public function testAmbiguousXmpSidecarIsNotAutoAttached(): void
    {
        $root = sys_get_temp_dir().'/retaia-enqueue-xmp-ambiguous-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/shot.cr2', 'raw');
        file_put_contents($root.'/INBOX/shot.png', 'png');
        file_put_contents($root.'/INBOX/shot.xmp', 'xmp');
        putenv('APP_INGEST_WATCH_PATH='.$root.'/INBOX');
        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        static::ensureKernelShutdown();

        static::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureTables($connection);

        foreach (['INBOX/shot.cr2', 'INBOX/shot.png', 'INBOX/shot.xmp'] as $path) {
            $connection->insert('ingest_scan_file', [
                'path' => $path,
                'size_bytes' => 100,
                'mtime' => '2026-02-10 12:00:00',
                'stable_count' => 2,
                'status' => 'stable',
                'first_seen_at' => '2026-02-10 12:00:00',
                'last_seen_at' => '2026-02-10 12:01:00',
            ]);
        }

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:enqueue-stable');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 20]);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $rawAssetUuid = $this->assetUuidFromPath('INBOX/shot.cr2');
        $xmpAssetUuid = $this->assetUuidFromPath('INBOX/shot.xmp');
        $rawAsset = $entityManager->find(Asset::class, $rawAssetUuid);
        self::assertInstanceOf(Asset::class, $rawAsset);
        self::assertNotContains('INBOX/shot.xmp', $rawAsset->getFields()['paths']['sidecars_relative'] ?? []);
        self::assertNull($entityManager->find(Asset::class, $xmpAssetUuid));
    }

    public function testEmptyExistingProxyDoesNotSkipGenerateProxyJob(): void
    {
        $root = sys_get_temp_dir().'/retaia-enqueue-empty-proxy-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/clip.mov', 'video');
        file_put_contents($root.'/INBOX/clip.lrf', '');
        putenv('APP_INGEST_WATCH_PATH='.$root.'/INBOX');
        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        static::ensureKernelShutdown();

        static::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->ensureTables($connection);

        foreach (['INBOX/clip.mov', 'INBOX/clip.lrf'] as $path) {
            $connection->insert('ingest_scan_file', [
                'path' => $path,
                'size_bytes' => 100,
                'mtime' => '2026-02-10 12:00:00',
                'stable_count' => 2,
                'status' => 'stable',
                'first_seen_at' => '2026-02-10 12:00:00',
                'last_seen_at' => '2026-02-10 12:01:00',
            ]);
        }

        $application = new Application(static::$kernel);
        $command = $application->find('app:ingest:enqueue-stable');
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => 20]);

        $videoAssetUuid = $this->assetUuidFromPath('INBOX/clip.mov');
        $jobCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM processing_job WHERE asset_uuid = :assetUuid', ['assetUuid' => $videoAssetUuid]);
        self::assertSame(3, $jobCount);

        $proxyJobCount = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM processing_job WHERE asset_uuid = :assetUuid AND job_type = :jobType',
            ['assetUuid' => $videoAssetUuid, 'jobType' => 'generate_proxy']
        );
        self::assertSame(1, $proxyJobCount);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $videoAsset = $entityManager->find(Asset::class, $videoAssetUuid);
        self::assertInstanceOf(Asset::class, $videoAsset);
        self::assertFalse((bool) ($videoAsset->getFields()['proxy_done'] ?? false));
    }

    public function testOrphanLrfIsMarkedQueuedAndCountedAsUnmatchedSidecar(): void
    {
        $root = sys_get_temp_dir().'/retaia-enqueue-orphan-lrf-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        file_put_contents($root.'/INBOX/orphan.lrf', 'proxy');
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
            'path' => 'INBOX/orphan.lrf',
            'size_bytes' => 100,
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

        self::assertStringContainsString('Queued 0 stable file(s). Missing: 0. Unmatched sidecars: 1.', $tester->getDisplay());
        self::assertSame('queued', (string) $connection->fetchOne('SELECT status FROM ingest_scan_file WHERE path = :path', ['path' => 'INBOX/orphan.lrf']));

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $orphanAssetUuid = $this->assetUuidFromPath('INBOX/orphan.lrf');
        self::assertNull($entityManager->find(Asset::class, $orphanAssetUuid));
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
        $connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_processing_job_asset_type ON processing_job (asset_uuid, job_type)');
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
