<?php

namespace App\Tests\Unit\Infrastructure\Asset;

use App\Infrastructure\Asset\AssetFieldViewProjector;
use PHPUnit\Framework\TestCase;

final class AssetFieldViewProjectorTest extends TestCase
{
    public function testPublicFieldsHidesProjectsAndNormalizesProjectsAndHistory(): void
    {
        $projector = new AssetFieldViewProjector();

        self::assertSame(['foo' => 'bar'], $projector->publicFields([
            'foo' => 'bar',
            'projects' => [['project_id' => 'p1']],
        ]));

        self::assertSame([
            [
                'project_id' => 'p1',
                'project_name' => 'Project',
                'created_at' => '2026-01-01T00:00:00Z',
                'description' => null,
            ],
        ], $projector->projects([
            'projects' => [
                ['project_id' => 'p1', 'project_name' => 'Project', 'created_at' => '2026-01-01T00:00:00Z', 'description' => null],
                ['project_id' => 'p1', 'project_name' => 'Duplicate', 'created_at' => '2026-01-01T00:00:00Z'],
            ],
        ]));

        self::assertSame(['A', 'B'], $projector->pathHistory([
            ['to' => 'A'],
            'B',
            ['to' => ''],
            42,
        ]));
        self::assertSame('NONE', $projector->transcriptStatus('oops'));
        self::assertSame('DONE', $projector->transcriptStatus('done'));
    }
}
