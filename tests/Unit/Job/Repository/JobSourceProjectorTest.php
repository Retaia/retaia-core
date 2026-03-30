<?php

namespace App\Tests\Unit\Job\Repository;

use App\Job\Repository\JobSourceProjector;
use App\Storage\BusinessStorageRegistryInterface;
use PHPUnit\Framework\TestCase;

final class JobSourceProjectorTest extends TestCase
{
    public function testSourceFromAssetFieldsValidatesStorageAndSanitizesSidecars(): void
    {
        $registry = $this->createMock(BusinessStorageRegistryInterface::class);
        $registry->method('has')->with('nas-main')->willReturn(true);

        $projector = new JobSourceProjector($registry);
        $source = $projector->sourceFromAssetFields([
            'paths' => [
                'storage_id' => 'nas-main',
                'original_relative' => 'INBOX/clip.mp4',
                'sidecars_relative' => ['/INBOX/clip.srt', '../bad', 'INBOX/clip.srt'],
            ],
        ], 'clip.mp4');

        self::assertSame([
            'storage_id' => 'nas-main',
            'original_relative' => 'INBOX/clip.mp4',
            'sidecars_relative' => ['INBOX/clip.srt'],
        ], $source);
    }

    public function testSourceFromAssetFieldsRejectsMissingStorage(): void
    {
        $registry = $this->createMock(BusinessStorageRegistryInterface::class);
        $registry->expects($this->never())
            ->method('has');
        $projector = new JobSourceProjector($registry);

        $this->expectException(\RuntimeException::class);
        $projector->sourceFromAssetFields(['paths' => ['original_relative' => 'INBOX/clip.mp4']], 'clip.mp4');
    }

    public function testSourceFromAssetFieldsRejectsUnknownStorageId(): void
    {
        $registry = $this->createMock(BusinessStorageRegistryInterface::class);
        $registry->method('has')->with('unknown-storage')->willReturn(false);

        $projector = new JobSourceProjector($registry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unknown-storage/');
        $projector->sourceFromAssetFields([
            'paths' => [
                'storage_id' => 'unknown-storage',
                'original_relative' => 'INBOX/clip.mp4',
            ],
        ], 'clip.mp4');
    }
}
