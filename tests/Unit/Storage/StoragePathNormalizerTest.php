<?php

namespace App\Tests\Unit\Storage;

use App\Storage\StoragePathNormalizer;
use PHPUnit\Framework\TestCase;

final class StoragePathNormalizerTest extends TestCase
{
    private StoragePathNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new StoragePathNormalizer();
    }

    public function testNormalizeAcceptsSafeRelativePaths(): void
    {
        self::assertSame('INBOX/clip.mp4', $this->normalizer->normalize('/INBOX\\clip.mp4'));
    }

    public function testNormalizeRejectsUnsafeParentTraversalPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->normalizer->normalize('../etc/passwd');
    }

    public function testNormalizeRejectsEmptyPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->normalizer->normalize('');
    }

    public function testNormalizeRejectsPathWithNullByte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->normalizer->normalize("foo\0bar");
    }

    public function testEnsureParentDirectoryCreatesOnlyConcreteParent(): void
    {
        $created = [];

        $this->normalizer->ensureParentDirectory('ARCHIVE/2026/clip.mp4', static function (string $path) use (&$created): void {
            $created[] = $path;
        });

        self::assertSame(['ARCHIVE/2026'], $created);
    }
}
