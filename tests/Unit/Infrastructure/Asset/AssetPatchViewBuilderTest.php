<?php

namespace App\Tests\Unit\Infrastructure\Asset;

use App\Asset\AssetRevisionTag;
use App\Asset\AssetState;
use App\Entity\Asset;
use App\Infrastructure\Asset\AssetPatchViewBuilder;
use App\Infrastructure\Asset\AssetProjectsNormalizer;
use PHPUnit\Framework\TestCase;

final class AssetPatchViewBuilderTest extends TestCase
{
    public function testBuildReturnsPublicPatchPayload(): void
    {
        $asset = new Asset(
            uuid: 'asset-1',
            mediaType: 'video',
            filename: 'clip.mp4',
            state: AssetState::READY,
            tags: ['news'],
            notes: 'Note',
            fields: [
                'foo' => 'bar',
                'projects' => [[
                    'project_id' => 'p1',
                    'project_name' => 'Project',
                    'created_at' => '2026-01-01T00:00:00Z',
                ]],
            ],
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-01T01:00:00+00:00'),
        );

        $payload = (new AssetPatchViewBuilder(new AssetProjectsNormalizer()))->build($asset);

        self::assertSame('asset-1', $payload['uuid']);
        self::assertSame(['foo' => 'bar'], $payload['fields']);
        self::assertSame($asset->getFields()['projects'], $payload['projects']);
        self::assertSame(AssetRevisionTag::fromAsset($asset), $payload['revision_etag']);
    }
}
