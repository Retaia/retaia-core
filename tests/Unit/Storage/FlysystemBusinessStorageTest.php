<?php

namespace App\Tests\Unit\Storage;

use App\Storage\BusinessStorageConfig;
use App\Storage\FlysystemAtomicWriter;
use App\Storage\FlysystemBusinessStorage;
use App\Storage\FlysystemFileCollector;
use App\Storage\StoragePathNormalizer;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;

final class FlysystemBusinessStorageTest extends TestCase
{
    public function testWriteAndMoveNormalizePathsAndCreateParents(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $createdDirectories = [];
        $filesystem->expects(self::exactly(2))->method('createDirectory')->willReturnCallback(static function (string $path) use (&$createdDirectories): void {
            $createdDirectories[] = $path;
        });
        $filesystem->expects(self::once())->method('write')->with('ARCHIVE/clip.txt', 'data');
        $filesystem->expects(self::once())->method('move')->with('INBOX/clip.txt', 'ARCHIVE/clip.txt');

        $storage = $this->storage($filesystem);

        $storage->write('/ARCHIVE\\clip.txt', 'data');
        $storage->move('/INBOX\\clip.txt', 'ARCHIVE/clip.txt');

        self::assertSame(['ARCHIVE', 'ARCHIVE'], $createdDirectories);
    }

    public function testListFilesSortsCollectorOutput(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->method('listContents')->willReturn(new DirectoryListing([
            new \League\Flysystem\FileAttributes('INBOX/b.txt', 1, null, 2),
            new \League\Flysystem\FileAttributes('INBOX/a.txt', 1, null, 1),
        ]));

        $storage = $this->storage($filesystem);

        $files = $storage->listFiles('INBOX');

        self::assertSame('INBOX/a.txt', $files[0]->path);
        self::assertSame('INBOX/b.txt', $files[1]->path);
    }

    private function storage(FilesystemOperator $filesystem): FlysystemBusinessStorage
    {
        return new FlysystemBusinessStorage(
            $filesystem,
            new BusinessStorageConfig('/tmp/retaia', 'INBOX'),
            new StoragePathNormalizer(),
            new FlysystemAtomicWriter(new StoragePathNormalizer()),
            new FlysystemFileCollector(),
        );
    }
}
