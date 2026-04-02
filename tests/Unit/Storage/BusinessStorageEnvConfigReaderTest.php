<?php

namespace App\Tests\Unit\Storage;

use App\Storage\BusinessStorageEnvConfigReader;
use App\Tests\Support\BusinessStorageEnvTrait;
use PHPUnit\Framework\TestCase;

final class BusinessStorageEnvConfigReaderTest extends TestCase
{
    use BusinessStorageEnvTrait;

    protected function tearDown(): void
    {
        $this->clearBusinessStorageEnv();
        parent::tearDown();
    }

    public function testReadAllReturnsNormalizedLocalConfig(): void
    {
        $root = sys_get_temp_dir().'/retaia-storage-reader-local-'.bin2hex(random_bytes(4));
        $this->configureBusinessStorages([[
            'id' => 'nas-main',
            'root_path' => 'var/storage/main',
            'watch_directory' => 'INBOX',
            'managed_directories' => ['INBOX', 'ARCHIVE'],
            'ingest_enabled' => false,
        ]], 'nas-main');

        $reader = new BusinessStorageEnvConfigReader($root);
        $configs = $reader->readAll();

        self::assertCount(1, $configs);
        self::assertSame('nas-main', $configs[0]->id);
        self::assertSame('local', $configs[0]->driver);
        self::assertSame($root.'/var/storage/main', $configs[0]->rootPath);
        self::assertSame('INBOX', $configs[0]->watchDirectory);
        self::assertSame(['INBOX', 'ARCHIVE'], $configs[0]->managedDirectories);
        self::assertFalse($configs[0]->ingestEnabled);
        self::assertSame('nas-main', $reader->resolveDefaultStorageId($configs));
    }

    public function testReadAllReturnsSmbConfig(): void
    {
        $this->configureBusinessStorages([[
            'id' => 'nas-smb',
            'driver' => 'smb',
            'host' => 'fileserver.local',
            'share' => 'media',
            'root_path' => 'retaia',
            'watch_directory' => 'INBOX',
            'username' => 'retaia',
            'password' => 'secret',
            'workgroup' => 'WORKGROUP',
            'timeout_seconds' => 30,
            'smb_version_min' => 'SMB2',
            'smb_version_max' => 'SMB3_11',
        ]], 'nas-smb');

        $reader = new BusinessStorageEnvConfigReader(sys_get_temp_dir());
        $configs = $reader->readAll();

        self::assertCount(1, $configs);
        self::assertSame('smb', $configs[0]->driver);
        self::assertSame('fileserver.local', $configs[0]->host);
        self::assertSame('media', $configs[0]->share);
        self::assertSame('retaia', $configs[0]->rootPrefix);
        self::assertSame('retaia', $configs[0]->username);
        self::assertSame('secret', $configs[0]->password);
        self::assertSame('WORKGROUP', $configs[0]->workgroup);
        self::assertSame('SMB2', $configs[0]->minProtocol);
        self::assertSame('SMB3_11', $configs[0]->maxProtocol);
        self::assertSame(30, $configs[0]->timeoutSeconds);
    }

    public function testResolveDefaultStorageIdRejectsUnknownConfiguredDefault(): void
    {
        $root = sys_get_temp_dir().'/retaia-storage-reader-default-'.bin2hex(random_bytes(4));
        $this->configureBusinessStorages([[
            'id' => 'nas-main',
            'root_path' => $root,
            'watch_directory' => 'INBOX',
        ]], 'nas-main');
        $_ENV['APP_STORAGE_DEFAULT_ID'] = 'unknown';
        $_SERVER['APP_STORAGE_DEFAULT_ID'] = 'unknown';
        putenv('APP_STORAGE_DEFAULT_ID=unknown');

        $reader = new BusinessStorageEnvConfigReader($root);
        $configs = $reader->readAll();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_STORAGE_DEFAULT_ID references unknown business storage');

        $reader->resolveDefaultStorageId($configs);
    }

    public function testReadAllRejectsDuplicateNormalizedIds(): void
    {
        $this->clearBusinessStorageEnv();
        $_ENV['APP_STORAGE_IDS'] = 'nas-main,nas_main';
        $_SERVER['APP_STORAGE_IDS'] = 'nas-main,nas_main';
        putenv('APP_STORAGE_IDS=nas-main,nas_main');

        $reader = new BusinessStorageEnvConfigReader(sys_get_temp_dir());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('collide once normalized');

        $reader->readAll();
    }
}
