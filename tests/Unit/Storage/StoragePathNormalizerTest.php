<?php

namespace App\Tests\Unit\Storage;

use App\Storage\StoragePathNormalizer;
use PHPUnit\Framework\TestCase;

final class StoragePathNormalizerTest extends TestCase
{
    public function testNormalizeAcceptsSafeRelativePaths(): void
    {
        $normalizer = new StoragePathNormalizer();

        self::assertSame('INBOX/clip.mp4', $normalizer->normalize('/INBOX\\clip.mp4'));
    }

    public function testNormalizeRejectsUnsafeParentTraversalPath(): void
    {
        $normalizer = new StoragePathNormalizer();

        $this->expectException(\InvalidArgumentException::class);
        $normalizer->normalize('../etc/passwd');
    }

    public function testNormalizeRejectsEmptyPath(): void
    {
        $normalizer = new StoragePathNormalizer();

        $this->expectException(\InvalidArgumentException::class);
        $normalizer->normalize('');
    }

    public function testNormalizeRejectsPathWithNullByte(): void
    {
        $normalizer = new StoragePathNormalizer();

        $this->expectException(\InvalidArgumentException::class);
        $normalizer->normalize("foo\0bar");
    }

    public function testEnsureParentDirectoryCreatesOnlyConcreteParent(): void
    {
        $normalizer = new StoragePathNormalizer();
        $created = [];

        $normalizer->ensureParentDirectory('ARCHIVE/2026/clip.mp4', static function (string $path) use (&$created): void {
            $created[] = $path;
        });

        self::assertSame(['ARCHIVE/2026'], $created);
    }
}
