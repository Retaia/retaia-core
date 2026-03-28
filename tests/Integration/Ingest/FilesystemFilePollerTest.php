<?php

namespace App\Tests\Integration\Ingest;

use App\Ingest\Service\FilesystemFilePoller;
use App\Storage\BusinessStorageDefinition;
use App\Storage\BusinessStorageConfig;
use App\Storage\BusinessStorageFile;
use App\Storage\BusinessStorageRegistry;
use App\Storage\BusinessStorageRegistryInterface;
use App\Storage\LocalBusinessStorageFactory;
use PHPUnit\Framework\TestCase;

final class FilesystemFilePollerTest extends TestCase
{
    public function testPollReturnsFilesSortedAndLimited(): void
    {
        $root = sys_get_temp_dir().'/retaia-watch-poller-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        mkdir($root.'/nested', 0777, true);
        file_put_contents($root.'/b.txt', 'BBBB');
        file_put_contents($root.'/a.txt', 'A');
        file_put_contents($root.'/nested/c.txt', 'CCC');

        $poller = new FilesystemFilePoller($this->storageRegistry($root));

        $items = $poller->poll(2);

        self::assertCount(2, $items);
        self::assertSame('nas-main', $items[0]['storage_id']);
        self::assertSame('a.txt', $items[0]['path']);
        self::assertSame('b.txt', $items[1]['path']);
        self::assertSame(1, $items[0]['size']);
    }

    public function testPollIgnoresSymlinks(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('symlink not available');
        }

        $root = sys_get_temp_dir().'/retaia-watch-poller-link-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        file_put_contents($root.'/safe.txt', 'SAFE');

        $outside = sys_get_temp_dir().'/retaia-watch-poller-outside-'.bin2hex(random_bytes(4)).'.txt';
        file_put_contents($outside, 'OUTSIDE');
        @symlink($outside, $root.'/outside.txt');

        $poller = new FilesystemFilePoller($this->storageRegistry($root));
        $items = $poller->poll(10);

        self::assertCount(1, $items);
        self::assertSame('safe.txt', $items[0]['path']);
    }

    public function testPollSkipsFilesWhenMetadataReadFails(): void
    {
        $root = sys_get_temp_dir().'/retaia-watch-poller-failure-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        file_put_contents($root.'/good.txt', 'GOOD');
        file_put_contents($root.'/broken.txt', 'BROKEN');

        $poller = new class($this->storageRegistry($root)) extends FilesystemFilePoller {
            protected function buildRow(string $storageId, string $watchDirectory, BusinessStorageFile $file): ?array
            {
                if (str_contains($file->path, 'broken.txt')) {
                    throw new \RuntimeException('simulated metadata failure');
                }

                return parent::buildRow($storageId, $watchDirectory, $file);
            }
        };

        $items = $poller->poll(10);

        self::assertCount(1, $items);
        self::assertSame('good.txt', $items[0]['path']);
    }

    public function testPollHandlesUnreadableFilesWithoutCrashing(): void
    {
        $root = sys_get_temp_dir().'/retaia-watch-poller-perms-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        file_put_contents($root.'/readable.txt', 'OK');
        file_put_contents($root.'/locked.txt', 'LOCKED');
        @chmod($root.'/locked.txt', 0000);

        $poller = new FilesystemFilePoller($this->storageRegistry($root));
        $items = $poller->poll(10);

        @chmod($root.'/locked.txt', 0644);

        self::assertNotSame([], $items);
        $paths = array_map(static fn (array $item): string => (string) $item['path'], $items);
        self::assertContains('readable.txt', $paths);
    }

    public function testPollHandlesUnreadableChildDirectoryWithoutCrashing(): void
    {
        $root = sys_get_temp_dir().'/retaia-watch-poller-unreadable-dir-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        mkdir($root.'/ok', 0777, true);
        file_put_contents($root.'/ok/readable.txt', 'OK');
        mkdir($root.'/blocked', 0777, true);
        file_put_contents($root.'/blocked/hidden.txt', 'HIDDEN');
        @chmod($root.'/blocked', 0000);

        $poller = new FilesystemFilePoller($this->storageRegistry($root));
        $items = $poller->poll(10);

        @chmod($root.'/blocked', 0755);

        self::assertNotSame([], $items);
        $paths = array_map(static fn (array $item): string => (string) $item['path'], $items);
        self::assertContains('ok/readable.txt', $paths);
    }

    private function storageConfig(string $root): BusinessStorageConfig
    {
        return new BusinessStorageConfig(dirname($root), basename($root));
    }

    private function storage(string $root): \App\Storage\BusinessStorageInterface
    {
        return (new LocalBusinessStorageFactory($this->storageConfig($root)))->create();
    }

    private function storageRegistry(string $root): BusinessStorageRegistryInterface
    {
        return new BusinessStorageRegistry('nas-main', [
            new BusinessStorageDefinition('nas-main', $this->storage($root), true),
        ]);
    }
}
