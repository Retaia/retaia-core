<?php

namespace App\Tests\Unit\Ingest;

use App\Ingest\Service\FilesystemFilePoller;
use App\Ingest\Service\WatchPathResolver;
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

        $resolver = new WatchPathResolver($root, '.');
        $poller = new FilesystemFilePoller($resolver);

        $items = $poller->poll(2);

        self::assertCount(2, $items);
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

        $resolver = new WatchPathResolver($root, '.');
        $poller = new FilesystemFilePoller($resolver);
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

        $resolver = new WatchPathResolver($root, '.');
        $poller = new class($resolver) extends FilesystemFilePoller {
            protected function buildRow(\SplFileInfo $file, string $root): ?array
            {
                if (str_contains($file->getFilename(), 'broken.txt')) {
                    throw new \RuntimeException('simulated metadata failure');
                }

                return parent::buildRow($file, $root);
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

        $resolver = new WatchPathResolver($root, '.');
        $poller = new FilesystemFilePoller($resolver);
        $items = $poller->poll(10);

        @chmod($root.'/locked.txt', 0644);

        self::assertNotSame([], $items);
        $paths = array_map(static fn (array $item): string => (string) $item['path'], $items);
        self::assertContains('readable.txt', $paths);
    }
}
