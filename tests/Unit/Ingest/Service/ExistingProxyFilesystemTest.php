<?php

namespace App\Tests\Unit\Ingest\Service;

use App\Ingest\Service\ExistingProxyFilesystem;
use App\Storage\BusinessStorageInterface;
use PHPUnit\Framework\TestCase;

final class ExistingProxyFilesystemTest extends TestCase
{
    public function testDelegatesReadOperationsAndMaterializesDerivedFile(): void
    {
        $storage = $this->createMock(BusinessStorageInterface::class);
        $storage->method('fileExists')->willReturnMap([
            ['proxy/video.lrf', true],
            ['.derived/asset-1/video.mp4', false],
        ]);
        $storage->expects(self::once())->method('move')->with('proxy/video.lrf', '.derived/asset-1/video.mp4');
        $storage->expects(self::once())->method('fileSize')->with('proxy/video.lrf')->willReturn(42);
        $storage->expects(self::once())->method('checksum')->with('proxy/video.lrf')->willReturn('sha');

        $filesystem = new ExistingProxyFilesystem();

        self::assertTrue($filesystem->isFile($storage, 'proxy/video.lrf'));
        self::assertSame(42, $filesystem->fileSize($storage, 'proxy/video.lrf'));
        self::assertSame('sha', $filesystem->hashSha256($storage, 'proxy/video.lrf'));
        self::assertSame('.derived/asset-1/video.mp4', $filesystem->materializeToDerived($storage, 'asset-1', 'proxy', 'proxy/video.lrf'));
    }
}
