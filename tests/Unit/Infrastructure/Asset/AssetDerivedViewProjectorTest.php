<?php

namespace App\Tests\Unit\Infrastructure\Asset;

use App\Derived\DerivedFile;
use App\Derived\DerivedFileRepositoryInterface;
use App\Entity\Asset;
use App\Infrastructure\Asset\AssetDerivedViewProjector;
use PHPUnit\Framework\TestCase;

final class AssetDerivedViewProjectorTest extends TestCase
{
    public function testProjectPrefersRepositoryBackedDerivedFiles(): void
    {
        $repository = $this->createMock(DerivedFileRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('listByAsset')->with('asset-1')->willReturn([
            new DerivedFile('1', 'asset-1', 'proxy_video', 'video/mp4', 10, null, 'a.mp4', new \DateTimeImmutable('2026-01-01T00:00:00Z')),
            new DerivedFile('2', 'asset-1', 'thumb', 'image/jpeg', 10, null, 'a.jpg', new \DateTimeImmutable('2026-01-01T00:00:00Z')),
        ]);

        $projector = new AssetDerivedViewProjector($repository);
        $asset = new Asset('asset-1', 'PHOTO', 'photo.jpg', fields: [
            'preview_video_url' => 'https://legacy.invalid/video.mp4',
            'thumbs' => ['https://legacy.invalid/thumb.jpg'],
        ]);

        self::assertSame([
            'preview_video_url' => '/api/v1/assets/asset-1/derived/proxy_video',
            'preview_audio_url' => null,
            'preview_photo_url' => null,
            'waveform_url' => null,
            'thumbs' => ['/api/v1/assets/asset-1/derived/thumb'],
        ], $projector->project($asset));
        self::assertTrue($projector->hasPreview($asset));
    }

    public function testProjectFallsBackToLegacyFieldUrlsWhenRepositoryIsEmpty(): void
    {
        $repository = $this->createMock(DerivedFileRepositoryInterface::class);
        $repository->expects(self::exactly(3))->method('listByAsset')->with('asset-1')->willReturn([]);

        $projector = new AssetDerivedViewProjector($repository);
        $asset = new Asset('asset-1', 'AUDIO', 'audio.wav', fields: [
            'derived' => [
                'preview_audio_url' => 'https://cdn/audio.mp3',
            ],
            'waveform_url' => 'https://cdn/waveform.json',
        ]);

        self::assertSame('https://cdn/audio.mp3', $projector->project($asset)['preview_audio_url']);
        self::assertSame('https://cdn/waveform.json', $projector->project($asset)['waveform_url']);
        self::assertTrue($projector->hasPreview($asset));
    }
}
