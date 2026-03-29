<?php

namespace App\Tests\Unit\Infrastructure\Asset;

use App\Infrastructure\Asset\AssetCanonicalPathsProjector;
use App\Storage\BusinessStorageRegistryInterface;
use PHPUnit\Framework\TestCase;

final class AssetCanonicalPathsProjectorTest extends TestCase
{
    public function testProjectReturnsCanonicalPathsAndSanitizedSidecars(): void
    {
        $registry = $this->createMock(BusinessStorageRegistryInterface::class);
        $registry->expects(self::once())->method('has')->with('nas-main')->willReturn(true);

        $projector = new AssetCanonicalPathsProjector($registry);

        self::assertSame([
            'storage_id' => 'nas-main',
            'original_relative' => 'INBOX/photo.jpg',
            'sidecars_relative' => ['INBOX/photo.xmp', 'INBOX/photo.srt'],
        ], $projector->project([
            'paths' => [
                'storage_id' => 'nas-main',
                'original_relative' => '/INBOX/photo.jpg',
                'sidecars_relative' => ['/INBOX/photo.xmp', '../bad', 'INBOX/photo.srt', 'INBOX/photo.xmp'],
            ],
        ], 'photo.jpg'));
    }

    public function testProjectRejectsUnknownStorage(): void
    {
        $registry = $this->createMock(BusinessStorageRegistryInterface::class);
        $registry->expects(self::once())->method('has')->with('missing')->willReturn(false);

        $projector = new AssetCanonicalPathsProjector($registry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('references unknown storage');

        $projector->project([
            'paths' => [
                'storage_id' => 'missing',
                'original_relative' => 'INBOX/photo.jpg',
            ],
        ], 'photo.jpg');
    }
}
