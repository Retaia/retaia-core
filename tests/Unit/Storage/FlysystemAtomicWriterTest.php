<?php

namespace App\Tests\Unit\Storage;

use App\Storage\FlysystemAtomicWriter;
use App\Storage\StoragePathNormalizer;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;

final class FlysystemAtomicWriterTest extends TestCase
{
    public function testWriteCreatesParentDirectoryBeforeWriting(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('createDirectory')->with('ARCHIVE/2026');
        $filesystem->expects(self::once())->method('write')->with('ARCHIVE/2026/clip.json', 'payload');

        (new FlysystemAtomicWriter(new StoragePathNormalizer()))->write($filesystem, 'ARCHIVE/2026/clip.json', 'payload');
    }

    public function testProbeWritableDirectoryReturnsFalseWhenWriteFails(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $writer = new FlysystemAtomicWriter(new StoragePathNormalizer());

        self::assertFalse($writer->probeWritableDirectory(
            $filesystem,
            'INBOX',
            static function (): void {
                throw new \RuntimeException('nope');
            },
            static function (): void {
            }
        ));
    }
}
