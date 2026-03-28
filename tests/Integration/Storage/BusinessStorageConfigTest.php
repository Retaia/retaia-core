<?php

namespace App\Tests\Integration\Storage;

use App\Storage\BusinessStorageConfig;
use PHPUnit\Framework\TestCase;

final class BusinessStorageConfigTest extends TestCase
{
    public function testBuildsAbsoluteWatchPathFromRelativeConfig(): void
    {
        $projectDir = sys_get_temp_dir().'/retaia-storage-config-'.bin2hex(random_bytes(4));
        mkdir($projectDir, 0777, true);
        mkdir($projectDir.'/docker/watch', 0777, true);

        $config = BusinessStorageConfig::fromConfiguredWatchPath($projectDir, './docker/watch');

        self::assertSame($projectDir.'/docker/watch', $config->absoluteWatchPath());
        self::assertSame($projectDir.'/docker', $config->rootPath());
        self::assertSame('watch', $config->watchDirectory());
        self::assertSame(['watch', 'ARCHIVE', 'REJECTS'], $config->managedDirectories());
    }

    public function testThrowsWhenWatchPathDoesNotResolveToConcreteDirectoryName(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configured watch path must point to a concrete watch directory');

        BusinessStorageConfig::fromConfiguredWatchPath('/tmp/retaia', '/');
    }
}
