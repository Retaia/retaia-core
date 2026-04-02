<?php

namespace App\Tests\Unit\Ingest\Service;

use App\Ingest\Service\SidecarPathResolver;
use PHPUnit\Framework\TestCase;

final class SidecarPathResolverTest extends TestCase
{
    private SidecarPathResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new SidecarPathResolver();
    }

    public function testNormalizePath(): void
    {
        self::assertSame('INBOX/clip.mp4', $this->resolver->normalizePath('/INBOX\\clip.mp4'));
    }

    public function testIsInboxPath(): void
    {
        self::assertTrue($this->resolver->isInboxPath('INBOX/clip.mp4'));
        self::assertFalse($this->resolver->isInboxPath('ARCHIVE/clip.mp4'));
    }

    public function testExtension(): void
    {
        self::assertSame('mp4', $this->resolver->extension('INBOX/clip.mp4'));
    }

    public function testBasename(): void
    {
        self::assertSame('clip', $this->resolver->basename('INBOX/clip.mp4'));
    }

    public function testSiblingAndProxyFolderResolution(): void
    {
        $existing = [
            'INBOX/clip.lrf',
            'INBOX/proxy/clip.mp4',
            'INBOX/clip.mp4',
        ];
        $fileExists = static fn (string $path): bool => in_array($path, $existing, true);

        self::assertSame('INBOX/clip.lrf', $this->resolver->findSiblingByExtensions('INBOX/clip.mp4', ['lrf'], $fileExists));
        self::assertSame(['INBOX/clip.lrf'], $this->resolver->findSiblingCandidatesByExtensions('INBOX/clip.mp4', ['lrf'], $fileExists));
        self::assertTrue($this->resolver->isInsideProxyFolder('INBOX/proxy/clip.mp4', ['proxy']));
        self::assertSame('INBOX/proxy/clip.mp4', $this->resolver->findProxyInSiblingProxyFolders('INBOX/clip.mp4', ['proxy'], ['mp4'], $fileExists));
        self::assertSame('INBOX/clip.mp4', $this->resolver->findProxyFolderParentOriginal('INBOX/proxy/clip.mp4', 'clip', ['proxy'], ['mp4'], $fileExists));
    }

    public function testSiblingResolutionWhenNoMatchExists(): void
    {
        $existing = [
            'INBOX/other.lrf',
            'INBOX/proxy/other.mp4',
        ];
        $fileExists = static fn (string $path): bool => in_array($path, $existing, true);

        self::assertNull(
            $this->resolver->findSiblingByExtensions('INBOX/clip.mp4', ['lrf'], $fileExists)
        );
        self::assertSame(
            [],
            $this->resolver->findSiblingCandidatesByExtensions('INBOX/clip.mp4', ['lrf'], $fileExists)
        );
    }

    public function testSiblingResolutionWithMultipleCandidates(): void
    {
        $resolver = new SidecarPathResolver();
        $existing = [
            'INBOX/clip.lrf',
            'INBOX/clip.xml',
            'INBOX/clip.mp4',
        ];
        $fileExists = static fn (string $path): bool => in_array($path, $existing, true);

        $candidates = $resolver->findSiblingCandidatesByExtensions(
            'INBOX/clip.mp4',
            ['lrf', 'xml'],
            $fileExists
        );

        sort($candidates);
        self::assertSame(
            ['INBOX/clip.lrf', 'INBOX/clip.xml'],
            $candidates
        );

        $firstMatch = $resolver->findSiblingByExtensions(
            'INBOX/clip.mp4',
            ['lrf', 'xml'],
            $fileExists
        );
        self::assertContains($firstMatch, ['INBOX/clip.lrf', 'INBOX/clip.xml']);
    }

    public function testNormalizeAndHelpersWithEdgeCasePaths(): void
    {
        $resolver = new SidecarPathResolver();

        $path = 'INBOX/Sub Folder/clip .v1 .mp4';
        $normalized = $resolver->normalizePath('\\INBOX\\\\Sub Folder//clip .v1 .mp4');
        self::assertSame($path, $normalized);
        self::assertTrue($resolver->isInboxPath($normalized));
        self::assertSame('mp4', $resolver->extension($normalized));
        self::assertSame('clip .v1 ', $resolver->basename($normalized));
    }

    public function testProxyFolderResolutionWhenProxyDoesNotExist(): void
    {
        $resolver = new SidecarPathResolver();
        $existing = [
            'INBOX/clip.mp4',
        ];
        $fileExists = static fn (string $path): bool => in_array($path, $existing, true);

        self::assertFalse(
            $resolver->isInsideProxyFolder('INBOX/clip.mp4', ['proxy'])
        );
        self::assertNull(
            $resolver->findProxyInSiblingProxyFolders('INBOX/clip.mp4', ['proxy'], ['mp4'], $fileExists)
        );
    }

    public function testProxyFolderParentOriginalWhenNoMatchingOriginal(): void
    {
        $resolver = new SidecarPathResolver();
        $existing = [
            'INBOX/other.mp4',
            'INBOX/proxy/other.mp4',
        ];
        $fileExists = static fn (string $path): bool => in_array($path, $existing, true);

        self::assertNull(
            $resolver->findProxyFolderParentOriginal(
                'INBOX/proxy/clip.mp4',
                'clip',
                ['proxy'],
                ['mp4'],
                $fileExists
            )
        );
    }
}
