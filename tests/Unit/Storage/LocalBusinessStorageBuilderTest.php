<?php

namespace App\Tests\Unit\Storage;

use App\Storage\BusinessStorageEnvConfig;
use App\Storage\BusinessStorageInterface;
use App\Storage\BusinessStorageConfig;
use App\Storage\LocalBusinessStorageBuilder;
use PHPUnit\Framework\TestCase;

final class LocalBusinessStorageBuilderTest extends TestCase
{
    public function testBuildCreatesLocalFlysystemStorage(): void
    {
        $receivedConfig = null;
        $builder = new LocalBusinessStorageBuilder(static function (BusinessStorageConfig $config) use (&$receivedConfig): BusinessStorageInterface {
            $receivedConfig = $config;

            return new class($config) implements BusinessStorageInterface {
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
            id: 'nas-main',
            driver: 'local',
            watchDirectory: 'INBOX',
            managedDirectories: ['INBOX', 'ARCHIVE'],
            ingestEnabled: true,
            rootPath: '/srv/retaia/storage'
        );

        $storage = $builder->build($config);

        self::assertTrue($builder->supports('local'));
        self::assertFalse($builder->supports('smb'));
        self::assertInstanceOf(BusinessStorageConfig::class, $receivedConfig);
        self::assertSame('/srv/retaia/storage/INBOX', $storage->absoluteWatchPath());
        self::assertSame(['INBOX', 'ARCHIVE'], $storage->managedDirectories());
    }
}
