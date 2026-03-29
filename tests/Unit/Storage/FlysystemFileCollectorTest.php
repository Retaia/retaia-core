<?php

namespace App\Tests\Unit\Storage;

use App\Storage\FlysystemFileCollector;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;

final class FlysystemFileCollectorTest extends TestCase
{
    public function testCollectReturnsFilesRecursively(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->method('listContents')->willReturnCallback(static function (string $directory, bool $deep = false): DirectoryListing {
            return match ($directory) {
                'INBOX' => new DirectoryListing([
                    new FileAttributes('INBOX/a.txt', 10, null, 100),
                    new DirectoryAttributes('INBOX/sub'),
                ]),
                'INBOX/sub' => new DirectoryListing([
                    new FileAttributes('INBOX/sub/b.txt', 5, null, 200),
                ]),
                default => new DirectoryListing([]),
            };
        });

        $files = (new FlysystemFileCollector())->collect($filesystem, 'INBOX', true);

        self::assertCount(2, $files);
        self::assertSame('INBOX/a.txt', $files[0]->path);
        self::assertSame('INBOX/sub/b.txt', $files[1]->path);
    }
}
