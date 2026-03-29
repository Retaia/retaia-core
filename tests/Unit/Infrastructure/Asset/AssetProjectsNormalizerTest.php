<?php

namespace App\Tests\Unit\Infrastructure\Asset;

use App\Infrastructure\Asset\AssetProjectsNormalizer;
use PHPUnit\Framework\TestCase;

final class AssetProjectsNormalizerTest extends TestCase
{
    public function testNormalizeProjectsTrimsAndDeduplicatesByProjectId(): void
    {
        $normalizer = new AssetProjectsNormalizer();

        self::assertSame([
            [
                'project_id' => 'p1',
                'project_name' => 'Project',
                'created_at' => '2026-01-01T00:00:00Z',
                'description' => 'Alpha',
            ],
        ], $normalizer->normalize([
            [
                'project_id' => ' p1 ',
                'project_name' => ' Project ',
                'created_at' => '2026-01-01T00:00:00Z',
                'description' => 'Alpha',
            ],
            [
                'project_id' => 'p1',
                'project_name' => 'Duplicate',
                'created_at' => '2026-01-01T00:00:00Z',
            ],
        ]));
    }

    public function testNormalizeRejectsInvalidProjectItems(): void
    {
        $normalizer = new AssetProjectsNormalizer();

        self::assertNull($normalizer->normalize('nope'));
        self::assertNull($normalizer->normalize([['project_id' => '', 'project_name' => 'x', 'created_at' => '2026-01-01T00:00:00Z']]));
        self::assertNull($normalizer->normalize([['project_id' => 'p1', 'project_name' => 'x', 'created_at' => 'bad-date']]));
        self::assertNull($normalizer->normalize([['project_id' => 'p1', 'project_name' => 'x', 'created_at' => '2026-01-01T00:00:00Z', 'description' => 42]]));
    }
}
