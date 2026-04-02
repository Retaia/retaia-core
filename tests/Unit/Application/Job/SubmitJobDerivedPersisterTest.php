<?php

namespace App\Tests\Unit\Application\Job;

use App\Application\Job\SubmitJobDerivedPersister;
use App\Derived\DerivedFileRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class SubmitJobDerivedPersisterTest extends TestCase
{
    public function testPersistMaterializesManifestEntries(): void
    {
        $repository = $this->createMock(DerivedFileRepositoryInterface::class);
        $calls = [];
        $repository->expects(self::exactly(2))
            ->method('upsertMaterialized')
            ->willReturnCallback(static function (string $assetUuid, string $kind, string $contentType, int $sizeBytes, ?string $sha256, string $storagePath) use (&$calls): void {
                $calls[] = [$assetUuid, $kind, $contentType, $sizeBytes, $sha256, $storagePath];
            });

        $persister = new SubmitJobDerivedPersister($repository);
        $persister->persist('asset-1', [
            'derived_manifest' => [
                ['kind' => 'thumb', 'ref' => '/thumbs/1.png', 'size_bytes' => 12, 'sha256' => 'abc'],
                ['kind' => 'proxy_audio', 'ref' => 'audio/1.mp3'],
            ],
        ]);

        self::assertSame([
            ['asset-1', 'thumb', 'image/png', 12, 'abc', 'thumbs/1.png'],
            ['asset-1', 'proxy_audio', 'audio/mpeg', 0, null, 'audio/1.mp3'],
        ], $calls);
    }

    public function testApplyFlagsMarksKnownDerivedKinds(): void
    {
        $persister = new SubmitJobDerivedPersister($this->createMock(DerivedFileRepositoryInterface::class));

        $fields = $persister->applyFlags([], [
            'derived_manifest' => [
                ['kind' => 'proxy_video', 'ref' => 'proxy.mp4'],
                ['kind' => 'thumb', 'ref' => 'thumb.jpg'],
                ['kind' => 'waveform', 'ref' => 'wave.json'],
            ],
        ]);

        self::assertTrue($fields['proxy_done']);
        self::assertTrue($fields['thumbs_done']);
        self::assertTrue($fields['waveform_done']);
    }
}
