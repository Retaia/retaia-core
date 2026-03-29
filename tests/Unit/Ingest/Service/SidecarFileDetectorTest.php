<?php

namespace App\Tests\Unit\Ingest\Service;

use App\Ingest\Service\SidecarDetectionRules;
use App\Ingest\Service\SidecarFileDetector;
use App\Ingest\Service\SidecarPathResolver;
use PHPUnit\Framework\TestCase;

final class SidecarFileDetectorTest extends TestCase
{
    public function testDetectProxyFileForRawJpegAndFolderProxy(): void
    {
        $detector = new SidecarFileDetector(new SidecarDetectionRules(), new SidecarPathResolver());
        $existing = [
            'INBOX/shot.cr3',
            'INBOX/shot.jpg',
            'INBOX/proxy/interview.mp4',
            'INBOX/interview.mov',
        ];
        $fileExists = static fn (string $path): bool => in_array($path, $existing, true);

        self::assertSame([
            'path' => 'INBOX/shot.jpg',
            'type' => 'raw_jpg',
            'kind' => 'proxy_photo',
            'original' => 'INBOX/shot.cr3',
        ], $detector->detectProxyFile('INBOX/shot.jpg', $fileExists));

        self::assertSame([
            'path' => 'INBOX/proxy/interview.mp4',
            'type' => 'proxy_folder',
            'kind' => 'proxy_video',
            'original' => 'INBOX/interview.mov',
        ], $detector->detectProxyFile('INBOX/proxy/interview.mp4', $fileExists));
    }

    public function testDetectExistingAuxiliarySidecarsAndUnmatchedReasons(): void
    {
        $detector = new SidecarFileDetector(new SidecarDetectionRules(true), new SidecarPathResolver());
        $existing = [
            'INBOX/clip.mp4',
            'INBOX/clip.srt',
            'INBOX/clip.lrv',
            'INBOX/clip.thm',
            'INBOX/photo.cr3',
            'INBOX/photo.jpg',
            'INBOX/photo.xmp',
        ];
        $fileExists = static fn (string $path): bool => in_array($path, $existing, true);

        self::assertSame(
            ['INBOX/clip.srt', 'INBOX/clip.lrv', 'INBOX/clip.thm'],
            $detector->detectExistingAuxiliarySidecarsForOriginal('INBOX/clip.mp4', $fileExists)
        );
        self::assertSame('missing_parent', $detector->auxiliaryUnmatchedReason('INBOX/missing.srt', $fileExists));
        self::assertTrue($detector->isProxyCandidatePath('INBOX/proxy/clip.mp4'));
        self::assertTrue($detector->isAuxiliarySidecarPath('INBOX/photo.xmp'));
    }
}
