<?php

namespace App\Tests\Unit\Storage;

use App\Storage\BusinessStorageEnvConfig;
use App\Storage\BusinessStorageConfig;
use App\Storage\BusinessStorageInterface;
use App\Storage\SmbBusinessStorageBuilder;
use PHPUnit\Framework\TestCase;

final class SmbBusinessStorageBuilderTest extends TestCase
{
    public function testBuildCreatesSmbStorageWithNormalizedDisplayRoot(): void
    {
        $receivedConfig = null;
        $receivedEnvConfig = null;
        $builder = new SmbBusinessStorageBuilder(static function (BusinessStorageConfig $storageConfig, BusinessStorageEnvConfig $config) use (&$receivedConfig, &$receivedEnvConfig): BusinessStorageInterface {
            $receivedConfig = $storageConfig;
            $receivedEnvConfig = $config;

            return new class($storageConfig) implements BusinessStorageInterface {
                public function __construct(private BusinessStorageConfig $config)
                {
                }

                public function absoluteWatchPath(): string { return $this->config->absoluteWatchPath(); }
                public function watchDirectory(): string { return $this->config->watchDirectory(); }
                public function managedDirectories(): array { return $this->config->managedDirectories(); }
                public function fileExists(string $path): bool { throw new \BadMethodCallException(); }
                public function directoryExists(string $path): bool { throw new \BadMethodCallException(); }
                public function createDirectory(string $path): void { throw new \BadMethodCallException(); }
                public function read(string $path): string { throw new \BadMethodCallException(); }
                public function write(string $path, string $contents): void { throw new \BadMethodCallException(); }
                public function writeAtomically(string $path, string $contents): void { throw new \BadMethodCallException(); }
                public function move(string $source, string $destination): void { throw new \BadMethodCallException(); }
                public function copy(string $source, string $destination): void { throw new \BadMethodCallException(); }
                public function deleteFile(string $path): void { throw new \BadMethodCallException(); }
                public function deleteDirectory(string $path): void { throw new \BadMethodCallException(); }
                public function fileSize(string $path): int { throw new \BadMethodCallException(); }
                public function lastModified(string $path): \DateTimeImmutable { throw new \BadMethodCallException(); }
                public function checksum(string $path, string $algorithm = 'sha256'): ?string { throw new \BadMethodCallException(); }
                public function listFiles(string $directory, bool $recursive = false): array { throw new \BadMethodCallException(); }
                public function probeWritableDirectory(string $directory): bool { throw new \BadMethodCallException(); }
            };
        });
        $config = new BusinessStorageEnvConfig(
            id: 'nas-smb',
            driver: 'smb',
            watchDirectory: 'INBOX',
            managedDirectories: ['INBOX', 'ARCHIVE'],
            ingestEnabled: true,
            host: 'fileserver.local',
            share: 'media',
            username: 'retaia',
            password: 'secret',
            workgroup: 'WORKGROUP',
            rootPrefix: '\\retaia\\incoming\\',
            minProtocol: 'SMB2',
            maxProtocol: 'SMB3_11',
            timeoutSeconds: 30,
        );

        $storage = $builder->build($config);

        self::assertTrue($builder->supports('smb'));
        self::assertFalse($builder->supports('local'));
        self::assertInstanceOf(BusinessStorageConfig::class, $receivedConfig);
        self::assertInstanceOf(BusinessStorageEnvConfig::class, $receivedEnvConfig);
        self::assertSame('smb://fileserver.local/media/retaia/incoming/INBOX', $storage->absoluteWatchPath());
        self::assertSame(['INBOX', 'ARCHIVE'], $storage->managedDirectories());
    }
}
