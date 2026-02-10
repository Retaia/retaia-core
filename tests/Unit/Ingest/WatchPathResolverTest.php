<?php

namespace App\Tests\Unit\Ingest;

use App\Ingest\Service\WatchPathResolver;
use PHPUnit\Framework\TestCase;

final class WatchPathResolverTest extends TestCase
{
    public function testResolveReturnsAbsolutePathForRelativeConfig(): void
    {
        $projectDir = sys_get_temp_dir().'/retaia-watch-resolver-'.bin2hex(random_bytes(4));
        mkdir($projectDir, 0777, true);
        mkdir($projectDir.'/docker/watch', 0777, true);

        $resolver = new WatchPathResolver($projectDir, './docker/watch');
        $resolved = $resolver->resolve();

        self::assertSame(realpath($projectDir.'/docker/watch'), $resolved);
    }

    public function testResolveThrowsWhenDirectoryMissing(): void
    {
        $resolver = new WatchPathResolver('/tmp/retaia', './missing-watch');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');
        $resolver->resolve();
    }
}
