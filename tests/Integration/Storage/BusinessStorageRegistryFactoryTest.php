<?php

namespace App\Tests\Integration\Storage;

use App\Storage\BusinessStorageRegistryFactory;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\BusinessStorageEnvTrait;

final class BusinessStorageRegistryFactoryTest extends TestCase
{
    use BusinessStorageEnvTrait;

    public function testSingleStorageCanOmitDefaultFlag(): void
    {
        $root = sys_get_temp_dir().'/retaia-storage-factory-single-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);

        $this->configureBusinessStorages([[
                'id' => 'nas-main',
                'root_path' => $root,
                'watch_directory' => 'INBOX',
            ]]);

        $factory = new BusinessStorageRegistryFactory($root);

        $registry = $factory->create();

        self::assertSame('nas-main', $registry->defaultStorageId());
    }

    public function testMultiStorageRequiresExplicitSingleDefaultFlag(): void
    {
        $root = sys_get_temp_dir().'/retaia-storage-factory-multi-'.bin2hex(random_bytes(4));
        mkdir($root.'/main/INBOX', 0777, true);
        mkdir($root.'/alt/INBOX', 0777, true);

        $this->configureBusinessStorages([
            [
                'id' => 'nas-main',
                'root_path' => $root.'/main',
                'watch_directory' => 'INBOX',
            ],
            [
                'id' => 'nas-alt',
                'root_path' => $root.'/alt',
                'watch_directory' => 'INBOX',
            ],
        ], 'nas-main');

        unset($_ENV['APP_STORAGE_DEFAULT_ID'], $_SERVER['APP_STORAGE_DEFAULT_ID']);
        putenv('APP_STORAGE_DEFAULT_ID');

        $factory = new BusinessStorageRegistryFactory($root);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_STORAGE_DEFAULT_ID must be configured explicitly');

        $factory->create();
    }

    public function testMultiStorageUsesExplicitDefaultId(): void
    {
        $root = sys_get_temp_dir().'/retaia-storage-factory-default-'.bin2hex(random_bytes(4));
        mkdir($root.'/main/INBOX', 0777, true);
        mkdir($root.'/alt/INBOX', 0777, true);

        $this->configureBusinessStorages([
            [
                'id' => 'nas-main',
                'root_path' => $root.'/main',
                'watch_directory' => 'INBOX',
            ],
            [
                'id' => 'nas-alt',
                'root_path' => $root.'/alt',
                'watch_directory' => 'INBOX',
            ],
        ], 'nas-alt');

        $factory = new BusinessStorageRegistryFactory($root);

        $registry = $factory->create();

        self::assertSame('nas-alt', $registry->defaultStorageId());
    }

    public function testSmbStorageDriverCanBeConfiguredExplicitly(): void
    {
        if (!$this->hasSmbClientBinary()) {
            self::markTestSkipped('smbclient is not available in this test environment.');
        }

        $root = sys_get_temp_dir().'/retaia-storage-factory-smb-'.bin2hex(random_bytes(4));

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
        ]]);

        $factory = new BusinessStorageRegistryFactory($root);

        $registry = $factory->create();

        self::assertSame('nas-smb', $registry->defaultStorageId());
        self::assertSame('smb://fileserver.local/media/retaia/INBOX', $registry->get('nas-smb')->storage->absoluteWatchPath());
    }

    public function testSmbStorageRequiresBackendSpecificConfiguration(): void
    {
        $root = sys_get_temp_dir().'/retaia-storage-factory-smb-invalid-'.bin2hex(random_bytes(4));

        $this->configureBusinessStorages([[
            'id' => 'nas-smb',
            'driver' => 'smb',
            'share' => 'media',
            'watch_directory' => 'INBOX',
            'username' => 'retaia',
            'password' => 'secret',
        ]]);

        $factory = new BusinessStorageRegistryFactory($root);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Business storage "nas-smb" requires host.');

        $factory->create();
    }

    public function testSmbStorageRejectsInvalidProtocolIdentifiers(): void
    {
        $root = sys_get_temp_dir().'/retaia-storage-factory-smb-proto-'.bin2hex(random_bytes(4));

        $this->configureBusinessStorages([[
            'id' => 'nas-smb',
            'driver' => 'smb',
            'host' => 'fileserver.local',
            'share' => 'media',
            'watch_directory' => 'INBOX',
            'username' => 'retaia',
            'password' => 'secret',
            'smb_version_min' => 'SMB9',
        ]]);

        $factory = new BusinessStorageRegistryFactory($root);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires smb_version_min to be one of');

        $factory->create();
    }

    private function hasSmbClientBinary(): bool
    {
        $which = shell_exec('command -v smbclient 2>/dev/null');

        return is_string($which) && trim($which) !== '';
    }
}
