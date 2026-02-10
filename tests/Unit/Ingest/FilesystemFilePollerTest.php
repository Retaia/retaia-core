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
}

